# 19 — Backup and Recovery Guide

This guide explains **what** must be backed up for ResolveDesk, **how** to do it, and **how to restore** it.

> **Status:** the procedures below are documented, not proven. A full restore drill (database + attachments onto a
> clean server) has **not** been performed. The only restore actually done was for migration testing: the dev
> database backup was restored into a separate database (`resolvedesk_v2check`), V2 migrations were run there
> (migrate → rollback → migrate), the 34 tickets were confirmed intact and the copy was dropped
> (see `DEVELOPMENT_PROGRESS.md`). Do not claim disaster recovery is verified until you run section 7 for real.

All hosts, users and passwords are placeholders (`<...>`).

Related: [Production deployment](18-PRODUCTION-DEPLOYMENT.md), [Security, errors, logging and operations](23-SECURITY-ERRORS-LOGGING-OPERATIONS.md).

---

## 1. What makes up "the ResolveDesk data"

| Part | Where | Why it matters |
|---|---|---|
| **MySQL database** | database `resolvedesk` | Users, projects, clients, tickets, comments, internal notes, activity history, notifications, attachment **metadata** (`ticket_attachments`), sessions, cache, queued jobs, failed jobs |
| **Private attachment files** | `storage/app/private/ticket-attachments/YYYY/MM/<uuid>.<ext>` | The actual uploaded files. The database only stores `storage_path`, `original_name`, `mime_type`, `size` |
| **`.env` (secure config)** | project root | DB/SMTP/Reverb credentials and above all **`APP_KEY`** |
| Code | Git repository (tag/commit) | Re-deployable from Git; record which tag was running |

Not needed in a backup: `vendor/`, `node_modules/`, `public/build/` (rebuilt by Composer/npm), `storage/framework/*`
(compiled views, cache files), `bootstrap/cache/*`. Logs are optional.

### Why a database-only backup is NOT enough

`ticket_attachments.storage_path` points to a file on disk. If you restore only the database, every attachment row
still exists, the ticket page still lists the file, but `TicketAttachmentController::download()` finds nothing:

```php
if (! $disk->exists($attachment->storage_path)) {
    Log::error('Attachment file missing on disk', ['attachment_id' => $attachment->id, 'ticket_id' => $ticket->id]);
    abort(404, 'The file could not be found.');
}
```

The opposite (files without database) gives orphan files nobody can reach. So database and files must be backed up
**together, at (almost) the same moment** — a *coordinated backup*.

> **বাংলায়:** Database-এ শুধু file-এর ঠিকানা (path) থাকে, আসল file থাকে disk-এ। শুধু database backup নিলে restore-এর
> পরে ticket-এ attachment দেখা যাবে কিন্তু download করলে 404 আসবে। তাই database আর `storage/app/private` দুটোই
> একসাথে backup নিতে হয়।

---

## 2. Database backup with mysqldump

Use the read-only backup user from docs/18 (`SELECT, LOCK TABLES, SHOW VIEW, TRIGGER`). Put its password in an
option file instead of the command line (command-line passwords are visible in `ps`):

```ini
# /home/deploy/.my-backup.cnf   (chmod 600)
[client]
user=resolvedesk_backup
password=<backup-user-password>
host=127.0.0.1
```

```bash
mysqldump --defaults-extra-file=/home/deploy/.my-backup.cnf \
  --single-transaction --quick --routines --triggers \
  --default-character-set=utf8mb4 \
  resolvedesk | gzip > /var/backups/resolvedesk/db/resolvedesk-$(date +%F-%H%M).sql.gz
```

| Option | Why |
|---|---|
| `--single-transaction` | Takes a consistent InnoDB snapshot **without locking tables** — users can keep working during the backup |
| `--quick` | Streams rows instead of loading whole tables into memory |
| `--routines --triggers` | Completeness (ResolveDesk currently has none, harmless) |
| `utf8mb4` | Keeps Bangla text and emoji correct |

> **বাংলায়:** `--single-transaction` মানে backup শুরুর মুহূর্তের একটি "snapshot" নেওয়া হয়। Backup চলার সময় কেউ নতুন
> ticket বানালেও dump-এর ভেতরের data পরস্পর consistent থাকে, আর table lock না হওয়ায় application বন্ধ করতে হয় না।

Tables you may exclude to make the dump smaller (they are temporary): `sessions`, `cache`, `cache_locks`.
Keep `jobs` and `failed_jobs` if you want unsent emails to survive.

### Real local backup taken before V2

Before any V2 migration ran on the dev database, a mysqldump was taken:

```text
storage/app/backups/resolvedesk-before-v2-2026-11-26.sql
```

It is git-ignored (`storage/app/.gitignore` ignores everything except `private/`, `public/` and `.gitignore`) and lies
outside `public/`. It is a **local development** backup (XAMPP MariaDB 10.4), used to test the migrations on a copy.
On a real server keep backups outside the project directory entirely (e.g. `/var/backups/resolvedesk`).

---

## 3. Attachment backup

```bash
# Same moment as the database dump; rsync copies only new/changed files.
rsync -a --delete /var/www/resolvedesk/storage/app/private/ \
  /var/backups/resolvedesk/files/current/

# Or a dated archive:
tar -czf /var/backups/resolvedesk/files/attachments-$(date +%F-%H%M).tar.gz \
  -C /var/www/resolvedesk/storage/app private
```

Attachments are never modified or deleted by the app (no attachment deletion UI; tickets are never deleted), so
incremental copies work well.

### Coordinated backup order

1. Dump the database (snapshot time T).
2. Immediately copy `storage/app/private`.

Files uploaded between T and the end of step 2 are in the file copy but not in the dump — harmless orphans. The
reverse order would produce database rows without files, which is worse. That is why the **database goes first**.

`AttachmentService` uses the same idea at runtime: files are written first, the DB transaction second, and files are
deleted again if the transaction fails — so the database never references a file that was not stored.

---

## 4. Secure configuration backup (`.env` and APP_KEY)

- Store a copy of the production `.env` in a **password manager / secrets vault**, not next to the dumps in plain text.
- `APP_KEY` is the most important value: it encrypts sessions and signed data. Restoring with a different key logs
  everyone out and makes anything encrypted with the old key unreadable.
- Also note the deployed Git tag, PHP version and MySQL version with each backup.
- Never commit `.env` or backups to Git (`.env` is in `.gitignore`).

---

## 5. Example backup script and schedule

```bash
#!/usr/bin/env bash
# /usr/local/bin/resolvedesk-backup.sh   (owner root or deploy, chmod 700)
set -euo pipefail

STAMP=$(date +%F-%H%M)
BACKUP_ROOT=/var/backups/resolvedesk
APP=/var/www/resolvedesk

mkdir -p "$BACKUP_ROOT/db" "$BACKUP_ROOT/files"

# 1. Database first
mysqldump --defaults-extra-file=/home/deploy/.my-backup.cnf \
  --single-transaction --quick --default-character-set=utf8mb4 \
  resolvedesk | gzip > "$BACKUP_ROOT/db/resolvedesk-$STAMP.sql.gz"

# 2. Attachments second
tar -czf "$BACKUP_ROOT/files/attachments-$STAMP.tar.gz" -C "$APP/storage/app" private

# 3. Retention: keep 14 days locally
find "$BACKUP_ROOT/db" "$BACKUP_ROOT/files" -type f -mtime +14 -delete

# 4. Copy off the server (placeholder: object storage / another host)
# rclone copy "$BACKUP_ROOT" <remote>:resolvedesk-backups
```

```cron
# root crontab: nightly at 02:30
30 2 * * * /usr/local/bin/resolvedesk-backup.sh >> /var/log/resolvedesk-backup.log 2>&1
```

Also take a manual backup **before every deployment that has migrations** (docs/18 §14).

---

## 6. Retention and access

| Rule | Recommendation |
|---|---|
| Retention | e.g. 14 daily + 8 weekly + 6 monthly copies (adjust to company/legal requirements) |
| Off-site | At least one copy on another machine / object storage — a server failure must not destroy the backups |
| Access | Backup directory `chmod 700`, owned by root/deploy; not readable by `www-data` |
| Location | **Never inside `public/`** (would be downloadable) and **never in Git** |
| Encryption | Encrypt off-site copies (backups contain customer data, emails, password hashes) |
| Monitoring | Check the backup log / file size daily; an empty `.sql.gz` is a failed backup |

---

## 7. Restore procedure

Restore onto a **separate database first** whenever possible, never blindly over live data.

```bash
# 0. Stop writes
cd /var/www/resolvedesk
php artisan down
sudo supervisorctl stop resolvedesk-queue resolvedesk-reverb

# 1. Database (example: into a fresh database)
mysql -u root -p -e "CREATE DATABASE resolvedesk_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip < /var/backups/resolvedesk/db/resolvedesk-<stamp>.sql.gz | mysql -u root -p resolvedesk_restore
#    After checking it, point DB_DATABASE to it (or rename/replace the live DB in a planned step).

# 2. Attachments (same stamp as the dump)
tar -xzf /var/backups/resolvedesk/files/attachments-<stamp>.tar.gz -C /var/www/resolvedesk/storage/app
sudo chown -R www-data:www-data storage/app/private

# 3. Configuration: restore .env from the vault - SAME APP_KEY
# 4. Code: check out the Git tag that matches the backup, then
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force     # only if the code is newer than the backup schema
php artisan config:cache && php artisan route:cache && php artisan view:cache

# 5. Start again
sudo supervisorctl start resolvedesk-queue resolvedesk-reverb
php artisan up
```

Never use `migrate:fresh` or `db:wipe` as part of a restore — they delete everything.

---

## 8. Restore verification

After a restore (or during a drill on a test server), check:

```sql
-- Row counts compared with the numbers recorded at backup time
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM tickets;
SELECT COUNT(*) FROM ticket_comments;
SELECT COUNT(*) FROM ticket_attachments;
SELECT MAX(created_at) FROM tickets;        -- newest ticket = expected backup moment?
```

```bash
php artisan migrate:status                    # every migration "Ran"
curl https://<support.example.com>/health/ready   # {"status":"ok"}
# Count attachment files and compare with ticket_attachments rows
find storage/app/private/ticket-attachments -type f | wc -l
```

Functional checks:

- [ ] Log in as an admin (existing password works → users table restored, `APP_KEY` correct if sessions survive)
- [ ] Open a ticket with attachments and download one (file present, authorization works)
- [ ] Activity history and internal notes are visible to staff, hidden from the customer
- [ ] Create a new ticket → ticket number continues after the last one (no duplicate `TK-` number)
- [ ] An email job is processed (`queue:work` running, `queue:failed` empty)
- [ ] `storage/logs/laravel-*.log` has no new "Attachment file missing on disk" errors

Record the date, backup used, duration and problems of each drill. Until such a drill has been done on a clean
server, recovery is **documented but unverified**.

---

## 9. Interview summary

"A ResolveDesk backup has three parts: a `mysqldump --single-transaction` of the database, a copy of
`storage/app/private` with the attachment files, and the `.env` with the APP_KEY stored in a vault. Database-only
backups are not enough because the attachment table stores only file paths. I dump the database first, then copy the
files, keep backups outside `public/` and Git, restrict access, keep them off-site, and verify a restore with row
counts, migrate:status, the readiness endpoint and an attachment download. I took a real mysqldump before running the
V2 migrations and tested the migrations on a restored copy, but a full disaster-recovery drill has not been done yet."
