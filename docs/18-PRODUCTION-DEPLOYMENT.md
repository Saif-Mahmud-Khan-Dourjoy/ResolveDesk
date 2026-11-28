# 18 — Production Deployment Guide

This guide explains how to deploy ResolveDesk V2 on **one Linux server** with Nginx, PHP-FPM and MySQL 8.

> **Status: documented only, NOT executed.** The application was developed and tested on Windows
> (PHP 8.4.28, XAMPP MariaDB 10.4). A Linux/Nginx deployment has **not** been performed or verified
> (see `DEVELOPMENT_PROGRESS.md` → "NOT verified"). Treat every config file below as a reviewed
> starting point, not as a proven setup. Zero-downtime deployment is **not** implemented.

All hosts, passwords and keys below are **placeholders** (`<...>`). Never copy real secrets into docs or Git.

Related: [Backup and recovery](19-BACKUP-AND-RECOVERY.md), [Security, errors, logging and operations](23-SECURITY-ERRORS-LOGGING-OPERATIONS.md),
[Queue and email](14-QUEUE-AND-EMAIL-GUIDE.md), [Setup guide (local)](02-SETUP-GUIDE.md).

---

## 1. Architecture

```text
                      HTTPS :443
 Browser ─────────────────────────────► Nginx ──(FastCGI, unix socket)──► PHP-FPM ──► Laravel ──► MySQL 8
    │                                     │                                               ▲   ▲
    │  wss://<domain>/app/<key>           │  proxy_pass http://127.0.0.1:8080             │   │
    └─────────────────────────────────────┴──────────────► Reverb (php artisan reverb:start)  │
                                                                                          │   │
          Supervisor ─► php artisan queue:work   (reads the "jobs" table, sends emails) ──┘   │
          cron (every minute) ─► php artisan schedule:run ─► tickets:check-sla ───────────────┘
```

| Process | What it does in ResolveDesk | Started by |
|---|---|---|
| **Nginx** | Terminates HTTPS, serves files in `public/` (Bootstrap, `public/js/notifications.js`, `public/build`), sends PHP requests to PHP-FPM, proxies WebSocket traffic to Reverb | systemd |
| **PHP-FPM** | Runs Laravel for every HTTP request (`public/index.php`) | systemd |
| **MySQL 8** | All data: tickets, users, sessions (`SESSION_DRIVER=database`), cache + locks (`CACHE_STORE=database`), queue (`jobs`, `failed_jobs`) | systemd |
| **Queue worker** | Runs `App\Jobs\SendTicketEmail` (emails are sent after the DB commit, outside the web request) | Supervisor |
| **Reverb** | WebSocket server; pushes `notification.created` to private channel `users.{id}` | Supervisor |
| **Scheduler** | `routes/console.php` runs `tickets:check-sla` every minute (`withoutOverlapping()`) | cron |

**Never use `php artisan serve` in production.** It is a single-threaded development server.

> **Reverse proxy (বাংলায়):** Reverse proxy হলো এমন একটি server (এখানে Nginx) যা browser-এর request
> নিজে গ্রহণ করে, তারপর ভেতরের আসল service-এ (PHP-FPM বা Reverb) পাঠিয়ে দেয়। Browser শুধু Nginx-কে দেখে;
> Reverb-এর port 8080 বাইরে থেকে খোলা থাকে না। HTTPS certificate-ও শুধু Nginx-এ লাগে।

What happens if a process is down:

| Down | Effect |
|---|---|
| Queue worker | Emails wait in `jobs`; in-app notifications still work (they are NOT queued) |
| Reverb | Operations still succeed (broadcast is wrapped in try/catch after commit); browsers fall back to polling `GET /notifications/feed` every 5 s |
| Scheduler | SLA breach reminders are not sent; nothing else breaks |
| MySQL | Application unavailable; `/health/ready` returns 503 |

---

## 2. Server requirements

### PHP

`composer.json` requires `"php": "~8.4.0"` → install **PHP 8.4.x** (CLI + FPM). Laravel 13 and
`laravel/reverb` ^1.14 are the main dependencies.

Required/recommended extensions (Ubuntu package names in brackets, e.g. `php8.4-mbstring`):

| Extension | Why |
|---|---|
| `pdo_mysql` (`php8.4-mysql`) | MySQL connection |
| `mbstring`, `ctype`, `tokenizer`, `filter`, `hash`, `openssl`, `session` | Laravel framework requirements |
| `xml`, `dom` (`php8.4-xml`) | Framework / Composer packages |
| `curl` (`php8.4-curl`) | HTTP client, broadcaster calls to Reverb |
| `fileinfo` | Detecting the real MIME type of uploads (`mimes:` rule in `AttachmentService::validationRules()`) |
| `intl` (`php8.4-intl`) | Number/locale helpers |
| `zip` (`php8.4-zip`) | Composer downloads |
| `pcntl`, `posix` (usually built into CLI) | Clean signal handling for `queue:work` (`--timeout` needs `pcntl`) and Reverb |
| `opcache` | Performance (enable for FPM) |

Check with: `php -v` and `php -m`.

PHP upload limits must be at least the attachment limit (`config/resolvedesk.php`: 5 files × 5 MB):

```ini
; /etc/php/8.4/fpm/conf.d/99-resolvedesk.ini
upload_max_filesize = 6M
post_max_size = 32M
max_file_uploads = 10
expose_php = Off
```

### Other software

- Composer 2.x
- Node.js 20 LTS + npm (only needed to build `resources/js/realtime.js`; can run on a CI machine instead)
- MySQL 8.x
- Nginx, Supervisor (or systemd units), cron, logrotate
- A TLS certificate (e.g. Let's Encrypt / certbot)

---

## 3. Get the code and install dependencies

Run everything as a **non-root deploy user** (example: `deploy`), with PHP-FPM running as `www-data`.

```bash
sudo mkdir -p /var/www/resolvedesk
sudo chown deploy:www-data /var/www/resolvedesk
cd /var/www/resolvedesk
git clone <repository-url> .
git checkout <release-branch-or-tag>

# PHP dependencies without dev tools (no Pint, PHPUnit, Pail) and with an optimized class map.
composer install --no-dev --optimize-autoloader
```

### Frontend build

```bash
npm ci          # exact versions from package-lock.json
npm run build   # vite build -> public/build/
```

Only **one file** is bundled: `resources/js/realtime.js` (Laravel Echo + pusher-js), see `vite.config.js`:

```js
laravel({
    input: ['resources/js/realtime.js'],
    refresh: false,
}),
```

Bootstrap, icons, Chart.js and `public/js/notifications.js` are plain files in `public/` — no build step.

Important details:

- `VITE_REVERB_*` values are **compiled into the bundle at build time**. Fill `.env` (section 5) **before**
  `npm run build`, and rebuild whenever the Reverb key/host changes.
- `resources/views/layouts/app.blade.php` loads the bundle only if `public/build/manifest.json` exists. If the
  build is missing, the app still works with polling — so a missing build is a silent degradation, check for it.
- `node_modules/` is not needed at runtime; you may delete it after the build.

---

## 4. MySQL database and least-privilege user

```sql
CREATE DATABASE resolvedesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Application user: can read/write data and run migrations, nothing else.
CREATE USER 'resolvedesk_app'@'localhost' IDENTIFIED BY '<strong-random-password>';

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, INDEX, DROP, REFERENCES,
      LOCK TABLES
   ON resolvedesk.* TO 'resolvedesk_app'@'localhost';

-- Separate read-only user for backups (see docs/19).
CREATE USER 'resolvedesk_backup'@'localhost' IDENTIFIED BY '<another-strong-password>';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER ON resolvedesk.* TO 'resolvedesk_backup'@'localhost';
FLUSH PRIVILEGES;
```

- Never use `root` for the application (the local XAMPP setup uses `root` with an empty password — that is
  **local only**).
- `DROP`/`ALTER` are needed because `php artisan migrate --force` runs with the same user and migrations'
  `down()` drop columns/indexes. A stricter setup uses a separate migration user that only exists during deploys;
  then the runtime user needs only `SELECT, INSERT, UPDATE, DELETE`.
- Bind MySQL to `127.0.0.1` (`bind-address = 127.0.0.1`) so it is not reachable from the internet.
- The code relies on InnoDB row locks (`lockForUpdate()`, `sharedLock()`), so tables must be InnoDB (Laravel's default).

> **Least privilege (বাংলায়):** প্রতিটি account-কে শুধু ততটুকু permission দাও যতটুকু তার কাজের জন্য দরকার।
> Application যদি hack হয়, attacker শুধু ওই user-এর permission পাবে — সব database বা server নয়।

---

## 5. Production `.env`

Start from `.env.example` and change the values below. **Placeholders only — never commit this file**
(`.env` and `.env.production` are in `.gitignore`).

```dotenv
APP_NAME=ResolveDesk
APP_ENV=production
APP_KEY=base64:<generated-once-never-change>
APP_DEBUG=false
APP_URL=https://<support.example.com>
APP_TIMEZONE=Asia/Dhaka

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=resolvedesk
DB_USERNAME=resolvedesk_app
DB_PASSWORD=<strong-random-password>

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null

BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local
ATTACHMENT_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=<smtp.example.com>
MAIL_PORT=587
MAIL_USERNAME=<smtp-username>
MAIL_PASSWORD=<smtp-password-or-app-password>
MAIL_FROM_ADDRESS="<no-reply@example.com>"
MAIL_FROM_NAME="${APP_NAME}"

# Reverb: browsers and Laravel both talk to Nginx on 443 (wss/https);
# Nginx proxies /app and /apps to the Reverb process on 127.0.0.1:8080.
REVERB_APP_ID=<random-app-id>
REVERB_APP_KEY=<random-app-key>
REVERB_APP_SECRET=<random-app-secret>
REVERB_HOST=<support.example.com>
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Why each important value:

| Setting | Reason |
|---|---|
| `APP_DEBUG=false` | Otherwise errors show stack traces, SQL and environment values to users |
| `LOG_STACK=daily` | `config/logging.php` `daily` channel: one file per day, `LOG_DAILY_DAYS` (default 14) files kept. Local dev keeps `single` |
| `SESSION_SECURE_COOKIE=true` | `config/session.php` `secure` → cookie sent only over HTTPS. `http_only` (default `true`) and `same_site=lax` are already the defaults |
| `REVERB_SERVER_HOST=127.0.0.1` | `config/reverb.php` defaults to `0.0.0.0`; binding to localhost means port 8080 is reachable only through Nginx |
| `REVERB_HOST/PORT/SCHEME` | Used by the **server-side broadcaster** (`config/broadcasting.php` → `reverb.options`) and copied into `VITE_*` for the browser |
| `QUEUE_CONNECTION=database` | Jobs are stored in `jobs`; needs a worker (section 9) |
| `CACHE_STORE=database` | Cache, the assignment `Cache::lock()`, idempotency/email dedupe keys and `withoutOverlapping()` locks live in MySQL (`cache`, `cache_locks`) |
| `ATTACHMENT_DISK=local` | `config/resolvedesk.php` → disk `local` = `storage/app/private` (not public) |

Alternative for the broadcaster: if you prefer Laravel to talk to Reverb directly (not through Nginx), you can
set `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http` **and** hard-code the public values in
`VITE_REVERB_HOST=<support.example.com>`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https`. Either way works;
the version above is simpler to explain.

Protect the file:

```bash
chown deploy:www-data .env
chmod 640 .env
```

### Reverb keys

`.env.example` leaves `REVERB_APP_ID/KEY/SECRET` empty on purpose. Generate random values once
(e.g. `php artisan reverb:install` on a fresh setup, or any secure random generator) and keep them in the
server `.env` and your password manager. The secret must never reach the browser — only `REVERB_APP_KEY`
(public identifier) is compiled into the JS bundle.

---

## 6. APP_KEY management

`APP_KEY` encrypts session data, signed URLs and anything stored with `Crypt`.

```bash
php artisan key:generate --show   # first install only: prints a key, paste it into .env
```

Rules:

- Generate it **once**, on the first installation. Store a copy in a password manager / secure vault.
- **Never run `php artisan key:generate` during a routine deployment.** A new key logs every user out and makes
  existing encrypted data unreadable.
- When restoring a backup onto a new server, restore the **same** `APP_KEY` (see docs/19).
- If the key leaked, rotating it is a planned operation (Laravel supports `APP_PREVIOUS_KEYS` for this), not a
  deploy step.

---

## 7. File and directory permissions

```bash
cd /var/www/resolvedesk
sudo chown -R deploy:www-data .
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;

# Laravel must write here (logs, cache, sessions files, compiled views, attachments):
sudo chmod -R ug+rwX storage bootstrap/cache
sudo chgrp -R www-data storage bootstrap/cache
chmod 640 .env
```

Private attachments:

- `config/filesystems.php` → disk `local` has `'root' => storage_path('app/private')`.
- `AttachmentService::storeFiles()` writes to `storage/app/private/ticket-attachments/YYYY/MM/<uuid>.<ext>`.
- The **only** way to download is `GET /tickets/{ticket}/attachments/{attachment}`
  (`TicketAttachmentController::download()` → `Gate::authorize('downloadAttachment', ...)`).
- **Never** symlink or copy `storage/app/private` into `public/`. `php artisan storage:link` only links
  `storage/app/public` (unused by attachments) — it is not required for ResolveDesk.
- Nginx `root` is `public/`, so `storage/`, `.env`, `vendor/` and backups are outside the web root.

Note: the `local` disk has `'serve' => true` (Laravel default). Laravel then registers a `/storage/{path}` route for
that disk, but `Illuminate\Filesystem\ServeFile` rejects requests without a valid **signed** URL, and ResolveDesk
never generates signed URLs for attachments. So files are still only reachable through the authorized controller.

---

## 8. Nginx

`/etc/nginx/sites-available/resolvedesk` (enable with a symlink in `sites-enabled`, then `sudo nginx -t && sudo systemctl reload nginx`):

```nginx
# Redirect plain HTTP to HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name <support.example.com>;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name <support.example.com>;

    # Document root is public/ - NEVER the project root.
    root /var/www/resolvedesk/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/<support.example.com>/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/<support.example.com>/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    # add_header Strict-Transport-Security "max-age=31536000" always;  # enable after HTTPS is confirmed

    # Must allow the attachment limit (5 files x 5 MB) plus form data.
    client_max_body_size 32M;
    charset utf-8;
    server_tokens off;

    # Deny every hidden file (.env, .git, .htaccess ...) except Let's Encrypt challenges.
    location ~ /\.(?!well-known).* {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # Laravel front controller via PHP-FPM
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        internal;
    }

    # Any other .php file must not execute.
    location ~ \.php$ {
        return 404;
    }

    # ---- Laravel Reverb WebSocket reverse proxy -------------------------------
    # Browsers connect to wss://<support.example.com>/app/<REVERB_APP_KEY>
    # Laravel's broadcaster POSTs events to https://<support.example.com>/apps/<REVERB_APP_ID>/events
    location ~ ^/(app|apps)/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;   # longer than Reverb's ping interval (60 s)
    }
}
```

Notes:

- `Upgrade` / `Connection` headers turn the normal HTTP request into a WebSocket connection. Without them the
  browser gets a 400/426 and Echo stays "connecting" (the app then uses polling).
- Route conflict check: ResolveDesk has no web routes starting with `/app/` or `/apps/` (`routes/web.php`), so
  this location does not hide any page.
- `/broadcasting/auth` is a **normal Laravel route** (registered by `channels:` in `bootstrap/app.php`) and goes to
  PHP-FPM, not to Reverb.
- PHP-FPM receives the real client IP from Nginx (`REMOTE_ADDR`), so rate limits per IP work without trusted-proxy
  configuration. If you later put a load balancer / CDN in front of Nginx, configure
  `$middleware->trustProxies(at: '<proxy-ip>')` in `bootstrap/app.php` (not configured today).
- `AddRequestContext` reuses an incoming `X-Request-Id` if it matches `^[A-Za-z0-9\-]{8,64}$`. Optional: add
  `fastcgi_param HTTP_X_REQUEST_ID $request_id;` so Nginx access logs and Laravel logs share the same id.

### HTTPS

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d <support.example.com>
sudo certbot renew --dry-run
```

---

## 9. Long-running processes (Supervisor)

`/etc/supervisor/conf.d/resolvedesk.conf`:

```ini
[program:resolvedesk-queue]
command=/usr/bin/php /var/www/resolvedesk/artisan queue:work --sleep=1 --tries=3 --timeout=60
directory=/var/www/resolvedesk
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=70
redirect_stderr=true
stdout_logfile=/var/www/resolvedesk/storage/logs/queue-worker.log

[program:resolvedesk-reverb]
command=/usr/bin/php /var/www/resolvedesk/artisan reverb:start
directory=/var/www/resolvedesk
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/www/resolvedesk/storage/logs/reverb.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status          # both should be RUNNING
```

### Why `--timeout=60` must be shorter than `retry_after` 90

- `config/queue.php` → `database` connection: `'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90)`.
  If a job is "reserved" for more than 90 s, the database queue assumes the worker died and gives the job to
  another worker.
- `SendTicketEmail` declares `public int $timeout = 60;`, `public int $tries = 3;`, `public int $backoff = 10;`.
  The worker kills an attempt after 60 s (e.g. a hanging SMTP server), **before** 90 s, so the same email is never
  processed twice at the same time.
- Job-level properties override the command-line `--tries`/`--timeout`; the flags are a safety net for other jobs.
- `stopwaitsecs=70` gives a running job time to finish when Supervisor stops the worker.
- Remaining duplicate risk: `delivery_key` dedupe in `SendTicketEmail::handle()` uses the cache for 2 days; a crash
  between "SMTP accepted" and `Cache::put()` can still send one duplicate (documented limitation).

> **বাংলায়:** `retry_after` হলো "কত সেকেন্ড পরে queue ধরে নেবে worker মারা গেছে"। `timeout` হলো "worker নিজে কত
> সেকেন্ড পরে job বন্ধ করবে"। timeout ছোট হলে job আগেই বন্ধ হয়, তাই একই email দুইটা worker একসাথে পাঠায় না।

### systemd alternative

```ini
# /etc/systemd/system/resolvedesk-queue.service
[Unit]
Description=ResolveDesk queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/resolvedesk
ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=3 --timeout=60
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Create `resolvedesk-reverb.service` the same way with `ExecStart=/usr/bin/php artisan reverb:start`, then
`sudo systemctl enable --now resolvedesk-queue resolvedesk-reverb`.

---

## 10. Scheduler (cron)

One cron entry for the `www-data` user (`sudo crontab -u www-data -e`):

```cron
* * * * * cd /var/www/resolvedesk && php artisan schedule:run >> /dev/null 2>&1
```

`routes/console.php`:

```php
Schedule::command('tickets:check-sla')
    ->everyMinute()
    ->withoutOverlapping();
```

- `withoutOverlapping()` uses a cache lock (database cache store) so a slow run is not started twice.
- `onOneServer()` is not used — single-server deployment.
- Check: `php artisan schedule:list`.

---

## 11. Log rotation

- Laravel log: with `LOG_STACK=daily`, Laravel writes `storage/logs/laravel-YYYY-MM-DD.log` and deletes files older
  than `LOG_DAILY_DAYS` (14). No logrotate needed for these.
- Supervisor output files (`queue-worker.log`, `reverb.log`) grow forever → rotate them:

```text
# /etc/logrotate.d/resolvedesk
/var/www/resolvedesk/storage/logs/queue-worker.log
/var/www/resolvedesk/storage/logs/reverb.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

- Nginx and PHP-FPM logs are rotated by their distribution packages.
- Logs are inside `storage/`, outside `public/`, so they are never downloadable.

---

## 12. Health endpoints

| URL | Defined in | Checks | Response |
|---|---|---|---|
| `GET /up` | `bootstrap/app.php` → `health: '/up'` | **Liveness**: Laravel boots and can answer | 200 page, or 500 |
| `GET /health/ready` | `routes/web.php` (`health.ready`, `throttle:60,1`) | **Readiness**: database answers `SELECT 1` | `{"status":"ok"}` 200, or `{"status":"unavailable"}` 503 |

```php
Route::get('/health/ready', function () {
    try {
        DB::select('SELECT 1');
    } catch (Throwable $exception) {
        report($exception);

        return response()->json(['status' => 'unavailable'], 503);
    }

    return response()->json(['status' => 'ok']);
})->middleware('throttle:60,1')->name('health.ready');
```

No versions, hostnames or error messages are returned; the real error goes to the log via `report()`.
Neither endpoint checks the queue worker, scheduler or Reverb — see docs/23 for how to check those.

---

## 13. First installation (summary)

```bash
cd /var/www/resolvedesk
composer install --no-dev --optimize-autoloader
cp .env.example .env               # then edit with production values (section 5)
php artisan key:generate           # FIRST INSTALL ONLY
npm ci && npm run build
php artisan migrate --force        # creates all tables on the empty database
# Optional demo data only for a demo server - never on real production data:
# php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo supervisorctl start resolvedesk-queue resolvedesk-reverb
```

Then create the first real administrator (e.g. with `php artisan tinker`) and remove or deactivate any demo accounts.
The demo password `password` must never exist on a public server.

---

## 14. Routine deployment

```bash
cd /var/www/resolvedesk

# 0. Backup FIRST (docs/19): database + storage/app/private
# 1. Optional: maintenance mode (users see a 503 page during the update)
php artisan down

# 2. New code and dependencies
git fetch --all && git checkout <new-tag>
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 3. Database changes (incremental migrations only)
php artisan migrate --force

# 4. Rebuild caches (config:cache also clears the old config cache)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Restart long-running processes so they load the new code
php artisan queue:restart          # workers finish the current job, exit, Supervisor restarts them
php artisan reverb:restart         # Reverb stops gracefully; Supervisor starts it again
sudo systemctl reload php8.4-fpm   # clear OPcache

php artisan up
```

Why each step:

- `--force` is required because `migrate` asks for confirmation when `APP_ENV=production`.
- `config:cache` merges all config into one file. After it, `env()` returns `null` outside `config/*.php`.
  ResolveDesk calls `env()` only inside `config/` (checked: no `env(` in `app/`, `routes/`, `resources/views/`).
- `route:cache` works because all routes use controllers, except the `/health/ready` closure; Laravel 13 can
  cache closure routes, but if `route:cache` ever fails on it, move it to a small controller.
- Workers and Reverb are long-running PHP processes: they keep the **old** code in memory until restarted.
- `queue:restart` and `reverb:restart` use the cache to send the restart signal → works with `CACHE_STORE=database`.

**Never run** during deployment: `php artisan key:generate`, `migrate:fresh`, `migrate:refresh`, `db:wipe`,
`db:seed` (on real data).

---

## 15. Safe migration practice

- Every schema change is a **new incremental migration**. V2 added `2026_11_26_000001` … `000008` on top of the V1
  tables without touching existing data; existing tickets became global (`project_id = NULL`, `client_id = NULL`).
- New columns are nullable or have defaults, so old rows stay valid.
- **Backup before migrating** (docs/19). Local example: `storage/app/backups/resolvedesk-before-v2-2026-11-26.sql`
  was taken before any V2 migration.
- Test migrations on a **restored copy** first. This was actually done locally: on `resolvedesk_v2check`
  migrate → rollback → migrate succeeded with 34 tickets intact, and found a real `down()` bug (an index backing a
  foreign key had to be dropped after the FK) — see `DEVELOPMENT_PROGRESS.md`.
- Check what will run: `php artisan migrate --pretend` and `php artisan migrate:status`.
- Large tables: `ALTER TABLE` can lock a table; run such migrations in a quiet period.

---

## 16. Rollback considerations

There is **no automatic rollback**. Options, from safest to most drastic:

1. **Code-only problem** (no migration in the release): check out the previous tag, `composer install --no-dev
   --optimize-autoloader`, `npm ci && npm run build`, re-cache, `queue:restart`, `reverb:restart`.
2. **Migration problem**: `php artisan migrate:rollback --step=<n>` runs the `down()` methods of the last migrations.
   Warning: rolling back V2 columns **drops V2 data** in those columns (projects, clients, attachments metadata,
   versions…). Take a backup before rolling back too.
3. **Data damaged**: restore the pre-deploy backup (database **and** `storage/app/private`, docs/19).

Prefer "fix forward" (a new small migration) once real users have written data with the new schema.
Keep `.env` unchanged across rollbacks (especially `APP_KEY`). Rebuild the JS bundle if the Reverb values changed.

---

## 17. Post-deployment verification checklist

None of these were executed on a Linux server for this project. Run them after every deployment:

- [ ] `curl -I https://<support.example.com>/up` → `200`
- [ ] `curl https://<support.example.com>/health/ready` → `{"status":"ok"}`
- [ ] `curl -I http://<support.example.com>` → `301` to HTTPS
- [ ] `curl -I https://<support.example.com>/.env` → `403`/`404` (never the file)
- [ ] `curl -I https://<support.example.com>/storage/logs/laravel.log` → `404`
- [ ] Unknown URL shows a plain error page without stack trace (`APP_DEBUG=false`)
- [ ] Login works; session cookie has `Secure`, `HttpOnly`, `SameSite=Lax`
- [ ] Response has an `X-Request-Id` header
- [ ] `sudo supervisorctl status` → queue and Reverb `RUNNING`
- [ ] `php artisan schedule:list` shows `tickets:check-sla` every minute
- [ ] Create a ticket with an attachment; download it as an authorized user; another customer gets 403
- [ ] Assign a ticket; the agent's browser console/network shows a WebSocket to `wss://.../app/<key>` and a toast appears
- [ ] An email job is processed: `SELECT COUNT(*) FROM jobs;` returns to 0, `php artisan queue:failed` is empty
- [ ] `php artisan about` shows environment `production`, debug `OFF`, config/routes/views cached
- [ ] Too many fast requests (e.g. 6 wrong logins) produce the expected message / 429 page

---

## 18. Interview summary

"ResolveDesk runs as a normal Laravel monolith: Nginx serves `public/` and forwards PHP to PHP-FPM; MySQL stores
data, sessions, cache and the queue. Three extra processes run beside it: a Supervisor-managed queue worker for
emails, a Supervisor-managed Reverb WebSocket server behind an Nginx `/app` reverse proxy, and a one-line cron for the
scheduler. Deploys are `composer install --no-dev`, `npm ci && npm run build`, `migrate --force`, cache commands and
`queue:restart` / `reverb:restart`. APP_KEY is generated once and never during deploys. This is documented but has not
been deployed or verified on Linux yet."
