# 02 — Setup Guide (Windows, V2)

This guide explains how to install and run ResolveDesk V2 on **this** Windows PC, and what every important
command actually does. It matches the real environment recorded in `DEVELOPMENT_PROGRESS.md`.

Related: [Project overview](01-PROJECT-OVERVIEW.md) · [Database guide](03-DATABASE-GUIDE.md) ·
[Queue and email](14-QUEUE-AND-EMAIL-GUIDE.md) · [Caching](15-CACHING-GUIDE.md) · [Debugging](11-DEBUGGING-GUIDE.md) ·
[Real-time: Reverb and Echo](21-REALTIME-REVERB-ECHO.md) · [Production deployment](18-PRODUCTION-DEPLOYMENT.md) ·
[Backup and recovery](19-BACKUP-AND-RECOVERY.md)

---

## 1. Required software

| Software | Version used here | Notes |
|---|---|---|
| Windows | 10 Pro | Commands below are for PowerShell or Git Bash. |
| PHP | **8.4.28** at `C:\php-8.4.28\php.exe` | Loaded ini file: `C:\php-8.4.28\php.ini`. |
| Composer | 2.8.8 | Must run **on PHP 8.4.28**. |
| Laravel | 13.44.1 | Installed by Composer into `vendor/`. |
| Database | **MariaDB 10.4.27** (XAMPP) | The target is MySQL 8.x, but only MariaDB 10.4 was available and tested. |
| Node.js / npm | v20.19.5 / 10.8.2 | **V2: needed only to build the real-time bundle** (`resources/js/realtime.js`). Without the build the app still works and uses polling. |
| Web browser | any modern browser | Bootstrap, Bootstrap Icons and Chart.js are already in `public/vendor/`. |

Required PHP extensions (enable in `C:\php-8.4.28\php.ini` if missing): `pdo_mysql`, `mbstring`, `openssl`,
`fileinfo` (needed for attachment MIME detection), `curl`. Check with `C:\php-8.4.28\php.exe -m`.

## 2. PHP 8.4 and multiple PHP versions

This PC has an **older PHP** on the global Windows PATH (used by other projects) and **PHP 8.4.28** in
`C:\php-8.4.28`. Typing `php` runs the first `php.exe` on PATH — the old one.

**Rule: never change the permanent Windows PATH** and never modify the other PHP installation.
Prepend PHP 8.4 **only for the current terminal**, and repeat this in **every** new terminal
(including the extra terminals for the queue worker, Reverb and the scheduler):

```powershell
# PowerShell
$env:Path = "C:\php-8.4.28;$env:Path"
php -v          # must print: PHP 8.4.28
composer -V     # must show: PHP version 8.4.28
```

```bash
# Git Bash
export PATH="/c/php-8.4.28:$PATH"
php -v
composer -V
```

Or bypass PATH completely: `C:\php-8.4.28\php.exe artisan test`.

> **বাংলায়:** PATH হলো ফোল্ডারের তালিকা, যেখানে Windows `php` খোঁজে — প্রথমে যেটা পায় সেটাই চালায়। আমরা শুধু
> বর্তমান টার্মিনালে PHP 8.4-এর ফোল্ডার শুরুতে বসাই। টার্মিনাল বন্ধ করলে পরিবর্তন মুছে যায়, তাই অন্য প্রজেক্টের
> পুরনো PHP নষ্ট হয় না। চারটা টার্মিনাল খুললে চারটাতেই এটা করতে হবে।

`.vscode/settings.json` sets `php.validate.executablePath` and prepends `C:\php-8.4.28` to PATH for **new**
VS Code terminals of this workspace only. Still run `php -v` to confirm.

## 3. Composer on PHP 8.4

Composer checks the PHP it runs on against `composer.json`. **Never bypass platform requirements**
(no `--ignore-platform-reqs`).

| Command | What it does |
|---|---|
| `composer install` | Installs the exact versions from `composer.lock` into `vendor/` (includes `laravel/reverb` v1.14 since V2). |
| `composer update` | Resolves new versions and rewrites `composer.lock`. Do not run it casually. |
| `composer dump-autoload` | Rebuilds the class map. |
| `composer test` | `php artisan config:clear` + `php artisan test`. |

Note: `composer dev` starts all four local processes in one terminal (`php artisan serve`, `queue:listen`,
`reverb:start`, `schedule:work`) using `concurrently` (an npm dev dependency; run `npm ci` first).
`composer setup` is the Laravel default and runs `migrate --force` - do not use it on a database with real data;
follow the manual steps below instead.

## 4. Database (MariaDB / MySQL)

### 4.1 Start the server

XAMPP Control Panel → **MySQL** → **Start** (XAMPP labels it MySQL although it is MariaDB), or
`C:\xampp\mysql_start.bat`. It listens on `127.0.0.1:3306`, user `root`, empty password (local only).

### 4.2 Create the two databases

| Database | Used by |
|---|---|
| `resolvedesk` | Development (`.env` → `DB_DATABASE=resolvedesk`) — **contains data that must be preserved** |
| `resolvedesk_testing` | Automated tests (`phpunit.xml`) — wiped by `RefreshDatabase` on every run |

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS resolvedesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS resolvedesk_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 4.3 Known harmless error

On MariaDB 10.4 `php artisan db:show` may fail mentioning `performance_schema`. The app is not affected; use
`php artisan migrate:status`.

## 5. Environment variables (`.env`)

`.env` is git-ignored (secrets). `.env.example` is the template: `Copy-Item .env.example .env`.
Laravel reads `env('KEY')` only inside `config/*.php`.

| Key | Example / meaning |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL` | `ResolveDesk`, `local`, `true` (must be `false` in production), `http://127.0.0.1:8000` |
| `APP_KEY` | generated by `php artisan key:generate` — never share |
| `APP_TIMEZONE` | `Asia/Dhaka` (V2 kept it; timestamps are stored in this zone, not UTC) |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `mysql`, `127.0.0.1`, `3306`, `resolvedesk`, `root`, *(empty)* |
| `SESSION_DRIVER` | `database` |
| `QUEUE_CONNECTION` | `database` (jobs in `jobs`) |
| `CACHE_STORE` | `database` (cache rows in `cache`, atomic locks in `cache_locks`) |
| `BROADCAST_CONNECTION` | `reverb` (V2). Tests use `null`. |
| `MAIL_MAILER` | `log` → emails written to `storage/logs/laravel.log` |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | `stack`, `single`, `debug` locally (production: `daily` — see [18](18-PRODUCTION-DEPLOYMENT.md)) |
| `ATTACHMENT_DISK`, `ATTACHMENT_MAX_KB` | optional, read by `config/resolvedesk.php` (defaults `local`, `5120`) |

### 5.1 Reverb variables (V2) — names only

| Variable | Used by |
|---|---|
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Laravel (to publish events) and the Reverb server (to accept them). **Secret values — generate your own, never commit or print them.** |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Where Laravel and browsers reach Reverb (template: `localhost`, `8080`, `http`). |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT` | Optional: the address the Reverb process listens on (`config/reverb.php` defaults `0.0.0.0`, `8080`). |
| `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | Copied into the **browser bundle at build time** by Vite (`"${REVERB_APP_KEY}"` etc.). Only the public key goes to the browser — never the secret. |

The `.env.example` values are empty placeholders. `php artisan reverb:install` can generate the ID/key/secret.
(During V2 the installer did not write the keys, so random values were added to `.env` by hand.)

> **বাংলায়:** `VITE_` দিয়ে শুরু হওয়া ভেরিয়েবল `npm run build`-এর সময় JavaScript ফাইলের ভেতরে বসে যায়, তাই এগুলো
> ব্রাউজারে সবাই দেখতে পায়। এজন্য শুধু public key (`REVERB_APP_KEY`) পাঠানো হয়, secret কখনো না। `.env` বদলালে
> আবার `npm run build` দিতে হয়।

## 6. Full installation sequence (fresh machine)

```powershell
# 0. PHP 8.4 in this terminal
$env:Path = "C:\php-8.4.28;$env:Path"; php -v; composer -V

# 1. PHP packages (from composer.lock)
composer install

# 2. JavaScript packages + real-time bundle (V2)
npm ci            # exact versions from package-lock.json
npm run build     # vite build -> public/build/manifest.json + assets

# 3. .env and application key
Copy-Item .env.example .env
php artisan key:generate
#    then set DB_* and the REVERB_* values (section 5.1); rebuild with npm run build after changing VITE_*

# 4. Start MariaDB and create the databases (section 4)

# 5. Tables
php artisan migrate

# 6. Demo data (EMPTY database only)
php artisan db:seed
```

- `npm ci` installs only `laravel-echo`, `pusher-js`, `vite`, `laravel-vite-plugin` (`npm audit`: 0 vulnerabilities
  at the time of V2).
- `vite.config.js` builds **only** `resources/js/realtime.js`. The layout loads it with `@vite` only when
  `public/build/manifest.json` (or `public/hot`) exists, so skipping step 2 does not break pages.

### 6.1 Upgrading an existing V1 database (what was done on the dev DB)

1. **Back up first** (see [19](19-BACKUP-AND-RECOVERY.md)), e.g. `mysqldump` into `storage/app/backups/`.
2. `php artisan migrate` — runs only the 8 new, **additive** V2 migrations. Never `migrate:fresh`.
3. Optional: `php artisan db:seed --class=V2DemoDataSeeder` — **adds** 5 projects, 3 clients, 6 client-project and
   8 project-agent links with `firstOrCreate` / `syncWithoutDetaching`. It never changes existing users or tickets.

The V2 migrations (`database/migrations/2026_11_26_*`):

| File | What it adds |
|---|---|
| `000001_create_projects_table` | `projects` (name, unique code, description, is_active) |
| `000002_create_clients_table` | `clients` (name, unique code, email, phone, address, description, is_active) |
| `000003_add_client_id_to_users_table` | nullable `users.client_id` FK |
| `000004_create_project_pivot_tables` | `client_project`, `project_user` (unique pairs, restrict on delete) |
| `000005_add_v2_columns_to_tickets_table` | `project_id`, `client_id`, `sla_notified_at`, `version` (default 1), `request_token`; unique `(created_by, request_token)`; indexes `(project_id,status)`, `(client_id,status)`, `(status,due_at)` |
| `000006_add_visibility_to_comments_and_activities` | `ticket_comments.visibility` (default `public`), `ticket_activities.is_internal`, nullable `ticket_activities.user_id` (system activities) |
| `000007_create_ticket_attachments_table` | `ticket_attachments` metadata |
| `000008_add_notification_feed_index` | `notifications (notifiable_type, notifiable_id, created_at)` replaces the 2-column index |

Existing tickets keep `project_id = NULL`, `client_id = NULL` → they are **global** tickets. All migrations have a
`down()`; they were tested migrate → rollback → migrate on a restored copy (rolling back drops V2 data only).

> **বাংলায়:** "Additive migration" মানে শুধু নতুন টেবিল বা নতুন nullable কলাম যোগ করা — পুরনো ডাটা মোছা বা বদলানো
> হয় না। তাই চালু ডাটাবেসে `php artisan migrate` নিরাপদ, কিন্তু `migrate:fresh` সব টেবিল মুছে ফেলে — কখনো না।

## 7. Demo accounts (local only, password `password`)

| Role | Email |
|---|---|
| Admin | `admin@resolvedesk.test` |
| Agents | `sarah.agent@resolvedesk.test` (HRMS, PAYROLL), `david.agent@…` (ERP, HRMS), `fatima.agent@…` (PAYROLL, ACCT), `michael.agent@…` (ERP, INV) |
| Customers | `john.customer@example.com`, `emily.customer@…` (ACME), `rahim.customer@…` (GLOBEX), `olivia.customer@…` (standalone) |
| Inactive | `tanvir.customer@example.com` — login refused |

Customer ↔ client links exist only in a **freshly seeded** database. On the existing dev DB the customers stay
standalone (link them on the user edit page if wanted).

## 8. Running locally: four processes

Open **four terminals**, set the PHP 8.4 PATH in each, then:

| Terminal | Command | What it does | If it is not running |
|---|---|---|---|
| 1 | `php artisan serve` | Dev web server at http://127.0.0.1:8000 | No website |
| 2 | `php artisan queue:work` | Runs `SendTicketEmail` jobs from the `jobs` table | Emails wait in `jobs`; everything else works |
| 3 | `php artisan reverb:start` | WebSocket server (port 8080) for real-time toasts | Browser falls back to polling every 5 s |
| 4 | `php artisan schedule:work` | Runs the scheduler every minute → `tickets:check-sla` | SLA reminders are sent at the next run |

Useful extras: `php artisan reverb:start --debug` (prints connections and messages), `php artisan schedule:list`,
`php artisan tickets:check-sla` (run the SLA check once), `php artisan queue:work --stop-when-empty`.

**After changing PHP code or `.env`:** restart the worker (`php artisan queue:restart`, then start it again) and
restart Reverb (`Ctrl+C`, `php artisan reverb:start`), because long-running processes keep old code in memory.

> **বাংলায়:** ওয়েব সার্ভার শুধু রিকোয়েস্টের উত্তর দেয়। ইমেইল পাঠায় queue worker, সাথে সাথে নোটিফিকেশন পৌঁছায় Reverb,
> আর প্রতি মিনিটে SLA চেক করে scheduler — তাই লোকালি চারটা আলাদা প্রসেস লাগে। কোনোটা বন্ধ থাকলেও মূল কাজ (টিকিট)
> ঠিকমতো চলে — এটাকে graceful degradation বলে।

On a Linux server the scheduler is one cron line (`* * * * * php artisan schedule:run`) and the worker/Reverb are
kept alive by Supervisor or systemd — documented in [18](18-PRODUCTION-DEPLOYMENT.md), **not tested** here.

## 9. Artisan, migrations and seeders

| Command | What it actually does |
|---|---|
| `php artisan migrate` | Runs migrations not yet recorded in `migrations`. Safe to repeat. |
| `php artisan migrate:status` | Which migrations ran. |
| `php artisan migrate:rollback` | Runs `down()` of the last batch → **data loss** for those columns/tables. Ask first. |
| `php artisan db:seed` | `DatabaseSeeder` (calls `V2DemoDataSeeder` too). **Empty database only** (unique emails). |
| `php artisan db:seed --class=V2DemoDataSeeder` | Additive V2 demo projects/clients. Safe on existing data. |
| `php artisan migrate:fresh --seed` | **Drops all tables.** Never on the dev DB with real data; only on a throw-away DB. |
| `php artisan route:list --except-vendor` | All routes with middleware. |
| `php artisan optimize:clear` | Clears config, route, view and application caches. |
| `php artisan cache:clear` | Empties the `cache` table (also removes email de-dup keys). |

> **Warning:** `migrate:fresh`, `migrate:reset`, `migrate:rollback` and `db:wipe` delete data.

## 10. Code style (Pint, V2)

`pint.json` uses the Laravel preset plus `concat_space: one` (keeps the existing `'a' . 'b'` style).
Format only the files you changed:

```bash
vendor/bin/pint --dirty     # Git Bash
vendor\bin\pint --dirty     # PowerShell
vendor/bin/pint --test      # report only, change nothing
```

Line endings are LF (`.gitattributes` → `eol=lf`). Editors on Windows can silently write CRLF — check before committing.

## 11. SMTP (Gmail) — not verified

`MAIL_MAILER=log` was used and verified (emails appear in `storage/logs/laravel.log`). Real Gmail delivery was
**not** verified. To try it: set `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`,
`MAIL_USERNAME`, `MAIL_PASSWORD` (a 16-character **App Password**, needs 2-Step Verification),
`MAIL_FROM_ADDRESS`; then `php artisan config:clear` and restart the worker. Never commit the password.

## 12. Cache configuration (summary)

`CACHE_STORE=database` → `cache` table (keys prefixed `resolvedesk-cache-`) and `cache_locks` table for
`Cache::lock()`. Keys: `dashboard:admin:stats` (60 s), `ticket:active-categories` (3600 s),
`ticket-assignment` (lock, 10 s), `ticket-email-sent:{notification id}` (2 days), plus the scheduler's
`withoutOverlapping` lock. Tests use `CACHE_STORE=array`. Details: [Caching guide](15-CACHING-GUIDE.md).

## 13. Tests

`phpunit.xml` overrides the environment for tests:

```xml
<env name="BROADCAST_CONNECTION" value="null"/>
<env name="CACHE_STORE" value="array"/>
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="resolvedesk_testing"/>
<env name="MAIL_MAILER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
```

16 files in `tests/Feature`: `AuthTest`, `AuthorizationTest`, `CacheTest`, `ConsistencyTest`,
`InternalNotesAndAttachmentsTest`, `MySqlConcurrencyTest`, `NotificationTest`, `PageRenderingTest`,
`ProjectAndClientTest`, `QueueAndEmailTest`, `RealtimeAndDeliveryTest`, `SlaReportsAndHardeningTest`,
`TicketManagementTest`, `TicketScopeTest`, `TicketWorkflowTest`, `UserAndCategoryManagementTest`.
Last recorded run: **170 passed / 682 assertions** on MariaDB 10.4.

| Command | What it does |
|---|---|
| `php artisan test` | Whole suite (MariaDB must be running). |
| `php artisan test --filter=ConsistencyTest` | One class (or method name pattern). |
| `php artisan test tests/Feature/MySqlConcurrencyTest.php` | Two real DB connections testing row locks. |
| `php artisan test --stop-on-failure` | Stop at the first failure. |

## 14. Common setup problems

| Symptom | Cause | Fix |
|---|---|---|
| `php -v` shows an old version / Composer platform error | Old PHP on PATH | Section 2. Never `--ignore-platform-reqs`. |
| `could not find driver` | `pdo_mysql` disabled | Enable it in `C:\php-8.4.28\php.ini`, new terminal. |
| `SQLSTATE[HY000] [2002]` | MariaDB not running | Start MySQL in XAMPP. |
| **`php artisan test` hangs for a long time with no output** | **MariaDB is stopped**: every test waits for the connection timeout before failing | Start MariaDB first (XAMPP), stop the run with `Ctrl+C`, run again. |
| **`php artisan serve` ignores `$env:DB_DATABASE=...` (or `DB_DATABASE=... php artisan serve`)** | `serve` restarts PHP's built-in server and passes only a small allow-list of environment variables to it, so the child process reads `DB_DATABASE` from `.env` again | Change `.env` temporarily, or start the built-in server directly: `php -S 127.0.0.1:8000 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` (that is how the isolated-DB E2E check was run). |
| `No application encryption key has been specified.` | `APP_KEY` empty | `php artisan key:generate`. |
| `.env` change has no effect | Cached config or old long-running process | `php artisan config:clear`; restart worker, Reverb and scheduler. |
| No real-time toast, but notifications appear after ~5 s | Reverb not running, bundle not built, or wrong `VITE_REVERB_*` | `php artisan reverb:start`; `npm run build`; see [11](11-DEBUGGING-GUIDE.md) Part 4. |
| `Vite manifest not found` | Should not happen (layout checks the manifest) — if you added `@vite` elsewhere | `npm run build`. |
| Broadcasting error "app key/secret missing" | `BROADCAST_CONNECTION=reverb` without `REVERB_APP_*` values | Fill the REVERB values in `.env`, `php artisan config:clear`. Ticket operations still succeed (broadcast errors are caught and logged). |
| HTTP 429 "Too Many Requests" while testing | Named rate limiter (e.g. 10 tickets/min) | Wait one minute or `php artisan cache:clear` (local only). |
| Emails never appear | No queue worker | `php artisan queue:work`; check `queue:failed`. |
| `Duplicate entry` when seeding | `db:seed` on a non-empty DB | Use `--class=V2DemoDataSeeder` (additive) instead. |
| `npm ci` fails | `package-lock.json` / Node mismatch | Use Node 20; `npm install` only if you intend to update the lock file. |
| 419 Page Expired | Session expired / missing CSRF token | Refresh and log in again. |
| Times wrong by hours | `APP_TIMEZONE` missing | `APP_TIMEZONE=Asia/Dhaka`, `php artisan config:clear`. |
