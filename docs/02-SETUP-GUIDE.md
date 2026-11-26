# 02 — Setup Guide (Windows)

This guide explains how to install and run ResolveDesk on **this** Windows PC, and what every important
command actually does. It matches the real environment recorded in `DEVELOPMENT_PROGRESS.md`.

Related: [Project overview](01-PROJECT-OVERVIEW.md) · [Database guide](03-DATABASE-GUIDE.md) ·
[Queue and email](14-QUEUE-AND-EMAIL-GUIDE.md) · [Caching](15-CACHING-GUIDE.md) · [Debugging](11-DEBUGGING-GUIDE.md)

---

## 1. Required software

| Software | Version used here | Notes |
|---|---|---|
| Windows | 10 Pro | All commands below are for PowerShell or Git Bash. |
| PHP | **8.4.28** at `C:\php-8.4.28\php.exe` | Loaded ini file: `C:\php-8.4.28\php.ini`. |
| Composer | 2.8.8 | Must run **on PHP 8.4.28**. |
| Laravel | 13.44.1 | Installed by Composer into `vendor/` (requirement `^13.0`). |
| Database | **MariaDB 10.4.27** (XAMPP) | MySQL 8.x also works — the code only uses standard SQL plus `TIMESTAMPDIFF` and `DATE()`, which both support. |
| Web browser | any modern browser | Bootstrap, Bootstrap Icons and Chart.js are already in `public/vendor/`. |
| Node.js / npm | **not needed** | The layouts load CSS/JS with `asset(...)` from `public/`, not through Vite. |

Required PHP extensions (enable them in `C:\php-8.4.28\php.ini` if missing): `pdo_mysql`, `mbstring`,
`openssl`, `fileinfo`, `curl`. Check with:

```powershell
C:\php-8.4.28\php.exe -m
```

## 2. PHP 8.4 configuration and multiple PHP versions

This PC has **two PHP versions**:

- An **older PHP** on the global Windows PATH (used by other projects).
- **PHP 8.4.28** in `C:\php-8.4.28` (used by ResolveDesk).

If you just type `php`, Windows uses the **first** `php.exe` it finds on PATH — the old one. ResolveDesk needs
8.4 (`composer.json` contains `"php": "~8.4.0"`, which means ">= 8.4.0 and < 8.5.0").

**Rule: never change the permanent Windows PATH** and never modify the other PHP installation.
Instead, put PHP 8.4 at the front of PATH **only for the current terminal**.

> **বাংলায়:** PATH হলো ফোল্ডারের একটা তালিকা, যেখানে Windows `php` নামের প্রোগ্রাম খোঁজে — প্রথমে যেটা পায় সেটাই চালায়।
> আমরা শুধু বর্তমান টার্মিনালে `C:\php-8.4.28` কে তালিকার শুরুতে বসাই, তাই এই টার্মিনালে PHP 8.4 চলে।
> টার্মিনাল বন্ধ করলে পরিবর্তন মুছে যায়, ফলে অন্য প্রজেক্টের পুরনো PHP নষ্ট হয় না।

### 2.1 Temporary PATH — PowerShell

```powershell
$env:Path = "C:\php-8.4.28;$env:Path"
php -v          # must print: PHP 8.4.28
composer -V     # must show: PHP version 8.4.28
```

- `$env:Path = "C:\php-8.4.28;$env:Path"` — prepends the PHP 8.4 folder to PATH for this PowerShell window only.
- Run it again in **every new terminal**; it does not persist.

### 2.2 Temporary PATH — Git Bash

```bash
export PATH="/c/php-8.4.28:$PATH"
php -v
composer -V
```

Git Bash uses `/c/...` paths and `:` as the separator instead of `;`.

### 2.3 Using the absolute path (always works)

```powershell
C:\php-8.4.28\php.exe artisan --version
C:\php-8.4.28\php.exe artisan test
```

This bypasses PATH completely. Useful when you are unsure which PHP a terminal uses.

## 3. VS Code workspace configuration

The project contains `.vscode/settings.json` (it is the only `.vscode` file not ignored by `.gitignore`):

```json
{
    "php.validate.executablePath": "C:\\php-8.4.28\\php.exe",

    "terminal.integrated.env.windows": {
        "PATH": "C:\\php-8.4.28;${env:PATH}"
    }
}
```

- `php.validate.executablePath` — VS Code's PHP syntax checking uses PHP 8.4 (so PHP 8.4 syntax is not reported as an error).
- `terminal.integrated.env.windows.PATH` — every **new** terminal opened inside VS Code for this folder gets
  `C:\php-8.4.28` at the front of PATH automatically. It only affects this workspace, not Windows.
  Still run `php -v` to confirm — terminals opened before the setting existed keep the old PATH.

## 4. Composer on PHP 8.4

Composer is itself a PHP program. It checks the **PHP version it is running on** against `composer.json`.
`composer -V` prints both versions, for example: `Composer version 2.8.8 ... PHP version 8.4.28`.

If it shows the old PHP version, fix PATH (section 2) first.
**Never bypass platform requirements** (do not use `--ignore-platform-reqs`): the installed packages must
really match the PHP that runs the app.

| Command | What it does |
|---|---|
| `composer install` | Reads `composer.lock` and installs the exact locked package versions into `vendor/`. Use this after cloning. |
| `composer update` | Resolves **new** versions allowed by `composer.json` and rewrites `composer.lock`. Do not run it casually. |
| `composer dump-autoload` | Rebuilds `vendor/autoload.php` (class map). Useful after adding/moving classes if autoloading fails. |
| `composer test` | Script in `composer.json`: runs `php artisan config:clear` then `php artisan test`. |

Note: the `composer setup` script also runs `npm install` and `npm run build`. This project does not need npm,
so it is simpler to run the steps of section 7 manually.

## 5. MySQL / MariaDB configuration

### 5.1 Start the database server

XAMPP Control Panel → **MySQL** → **Start** (XAMPP labels it MySQL even though it is MariaDB),
or run `C:\xampp\mysql_start.bat`. It listens on `127.0.0.1:3306`, user `root`, empty password (XAMPP default — local only).

### 5.2 Create the two databases

| Database | Used by |
|---|---|
| `resolvedesk` | Development (`.env` → `DB_DATABASE=resolvedesk`) |
| `resolvedesk_testing` | Automated tests (`phpunit.xml` → `<env name="DB_DATABASE" value="resolvedesk_testing"/>`) |

Tests **wipe and rebuild** their database (`RefreshDatabase`), so they must never use the development database.

Create both once, for example in phpMyAdmin (http://localhost/phpmyadmin) or the MariaDB client:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS resolvedesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS resolvedesk_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

`utf8mb4` stores every Unicode character (including Bangla text and emoji); it matches the `charset` and
`collation` in `config/database.php`.

### 5.3 MySQL 8 instead of MariaDB

Nothing in the code changes. Create the same two databases and set `DB_USERNAME` / `DB_PASSWORD` in `.env`.
`DB_CONNECTION=mysql` is used for both MySQL and MariaDB here.

### 5.4 Known harmless error: `php artisan db:show`

On MariaDB 10.4, `php artisan db:show` may fail with an error that mentions **`performance_schema`**.
The command tries to read MySQL-8 statistics that this MariaDB setup does not provide.
**The application is not affected.** Use these instead:

```powershell
php artisan migrate:status
C:\xampp\mysql\bin\mysql.exe -u root resolvedesk -e "SHOW TABLES;"
```

## 6. Environment variables (`.env`)

`.env` holds settings for **this machine** and is ignored by git (it can contain secrets).
`.env.example` is the committed template. Create your file with:

```powershell
Copy-Item .env.example .env
```

Laravel reads `.env` through `env('KEY')` **only inside `config/*.php`**; the rest of the code uses `config('...')`.

Keys this project actually relies on (values from `.env.example`):

| Key | Example value | Meaning |
|---|---|---|
| `APP_NAME` | `ResolveDesk` | Shown in titles and the email "from" name. Also used for the cache key prefix. |
| `APP_ENV` | `local` | `local` / `testing` / `production`. Lazy-loading protection is on when not `production`. |
| `APP_KEY` | *(generated, never share)* | Encryption key — see section 7. |
| `APP_DEBUG` | `true` | Detailed error pages. **Must be `false` in production.** |
| `APP_URL` | `http://127.0.0.1:8000` | Base URL used for links, e.g. the ticket link in emails. |
| `APP_TIMEZONE` | `Asia/Dhaka` | Read by `config/app.php` (`'timezone' => env('APP_TIMEZONE', 'UTC')`). Affects `now()`, SLA deadlines and displayed times. |
| `DB_CONNECTION` | `mysql` | Database driver. |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | Database server address. |
| `DB_DATABASE` | `resolvedesk` | Development database. |
| `DB_USERNAME` / `DB_PASSWORD` | `root` / *(empty)* | Database login (XAMPP default). |
| `SESSION_DRIVER` | `database` | Sessions stored in the `sessions` table. |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before the session expires. |
| `QUEUE_CONNECTION` | `database` | Queued jobs stored in the `jobs` table. |
| `CACHE_STORE` | `database` | Cache stored in the `cache` table. |
| `MAIL_MAILER` | `log` | Emails are written to `storage/logs/laravel.log` instead of being sent. |
| `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | — | SMTP settings (section 11). |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `no-reply@resolvedesk.test` / `${APP_NAME}` | Sender of all emails. |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stack` / `debug` | Logging to `storage/logs/laravel.log`. |
| `BCRYPT_ROUNDS` | `12` | Password hashing cost (tests use `4` for speed). |

Redis, Memcached and AWS keys exist in the template but are **not used** by this project.

## 7. APP_KEY

```powershell
php artisan key:generate
```

- Generates a random 32-byte key and writes it into `.env` as `APP_KEY=base64:...`.
- Laravel uses it to **encrypt cookies and the session cookie** and anything passed to `encrypt()`.
- Without it you get the error *"No application encryption key has been specified."*
- If you change it, all existing sessions/cookies become invalid (everybody is logged out).
- Never commit or share it. Each machine/environment should have its own key.

> **বাংলায়:** APP_KEY হলো অ্যাপের গোপন চাবি। এটা দিয়ে কুকি আর সেশন এনক্রিপ্ট করা হয়, যাতে কেউ ব্রাউজারে কুকি
> বদলে অন্য ইউজার সাজতে না পারে। চাবি না থাকলে অ্যাপ চলবে না, আর চাবি ফাঁস হলে নিরাপত্তা ঝুঁকিতে পড়ে — তাই এটা কখনো গিটে রাখা যাবে না।

## 8. Full installation sequence

```powershell
# 0. Use PHP 8.4 in this terminal
$env:Path = "C:\php-8.4.28;$env:Path"
php -v
composer -V

# 1. Install PHP packages from composer.lock
composer install

# 2. Create .env and the application key
Copy-Item .env.example .env
php artisan key:generate

# 3. Start MariaDB in XAMPP and create the two databases (section 5.2)

# 4. Create all tables
php artisan migrate

# 5. Insert demo data
php artisan db:seed

# 6. Start the web server (terminal 1)
php artisan serve

# 7. Start the queue worker (terminal 2, also with PHP 8.4 on PATH)
php artisan queue:work
```

Open http://127.0.0.1:8000 and log in with a demo account (password `password`, local only):

| Role | Email |
|---|---|
| Admin | `admin@resolvedesk.test` |
| Agent | `sarah.agent@resolvedesk.test` (also `david.agent@...`, `fatima.agent@...`, `michael.agent@...`) |
| Customer | `john.customer@example.com` (also `emily.customer@...`, `rahim.customer@...`, `olivia.customer@...`) |
| Inactive customer | `tanvir.customer@example.com` — login is refused (demonstrates `is_active`) |

## 9. Artisan commands, migrations and seeders

`php artisan` is Laravel's command-line tool (the `artisan` file in the project root).

| Command | What it actually does |
|---|---|
| `php artisan --version` | Prints the Laravel version (13.44.1). |
| `php artisan migrate` | Runs every file in `database/migrations` that is **not yet recorded** in the `migrations` table, in filename order, and records it. Safe to repeat. |
| `php artisan migrate:status` | Lists each migration and whether it has run. |
| `php artisan migrate:rollback` | Calls `down()` of the last batch of migrations (drops those tables → **data loss** for them). |
| `php artisan db:seed` | Runs `Database\Seeders\DatabaseSeeder::run()`: creates 10 users, 7 categories (1 inactive) and 32 tickets with activities, comments and notifications. Run it on an **empty** database — running it twice fails because emails/category names are unique. It also clears the two cache keys. |
| `php artisan migrate:fresh --seed` | **DROPS ALL TABLES**, runs every migration again, then seeds. **Destroys all data.** Use only on your local development database, and only when you really want a clean demo. |
| `php artisan route:list --except-vendor` | Shows every route: method, URL, name, controller and middleware. |
| `php artisan tinker` | Interactive PHP shell with the app loaded, e.g. `App\Models\Ticket::count()`. |
| `php artisan config:clear` | Deletes the cached config file so `.env` changes are read again. |
| `php artisan cache:clear` | Empties the application cache store (the `cache` table here). |
| `php artisan optimize:clear` | Clears config, route, view and application caches at once. |

> **Warning:** `migrate:fresh`, `migrate:rollback`, `migrate:reset` and `db:wipe` delete data.
> Never run them against a database whose data you need.

> **বাংলায়:** Migration হলো ডাটাবেসের টেবিল তৈরির কোড — `migrate` শুধু নতুন (এখনো না চালানো) migration চালায়, তাই বারবার চালানো নিরাপদ।
> Seeder নমুনা ডাটা ঢোকায়। কিন্তু `migrate:fresh` সব টেবিল মুছে নতুন করে বানায় — এতে সব ডাটা হারিয়ে যায়, তাই শুধু লোকাল ডেমো ডাটাবেসে ব্যবহার করবে।

## 10. Application and queue worker startup

### 10.1 Web server

```powershell
php artisan serve
```

Starts PHP's built-in development web server at http://127.0.0.1:8000 and sends every request to
`public/index.php`. Stop it with `Ctrl+C`. It is for development only (not for production).

### 10.2 Queue worker

Emails are **not sent during the web request**. `TicketService::queueEmail()` inserts a `SendTicketEmail`
job row into the `jobs` table after the transaction commits. A separate long-running process must execute it:

| Command | What it does |
|---|---|
| `php artisan queue:work` | Starts a worker that keeps polling the `jobs` table, runs each job's `handle()` and deletes the row on success. Leave it running in its own terminal. |
| `php artisan queue:work --stop-when-empty` | Processes all waiting jobs, then exits (used during verification). |
| `php artisan queue:failed` | Lists jobs that failed after all attempts (`SendTicketEmail::$tries = 3`); they are stored in `failed_jobs`. |
| `php artisan queue:retry all` | Moves every failed job back into the queue. You can also pass one UUID: `php artisan queue:retry <uuid>`. |
| `php artisan queue:restart` | Tells running workers to exit gracefully after the current job. **Run it after changing code or `.env`**, because a worker loads the app once and keeps old code in memory. Then start the worker again (there is no Supervisor on this PC). |

If the worker is not running: tickets, activities and **in-app notifications still work** (notifications are
not queued). Only emails wait in the `jobs` table until a worker starts.

> **বাংলায়:** Queue মানে কাজের লাইন। ওয়েব রিকোয়েস্ট শুধু "ইমেইল পাঠাতে হবে" — এই কাজটা `jobs` টেবিলে লিখে রাখে, তাই
> ইউজারকে অপেক্ষা করতে হয় না। আলাদা টার্মিনালে চলা `queue:work` প্রসেস লাইন থেকে কাজ তুলে আসলে ইমেইল পাঠায়।
> কোড বদলালে `queue:restart` দিতে হয়, কারণ worker পুরনো কোড মেমোরিতে ধরে রাখে।

Details: [Queue and email guide](14-QUEUE-AND-EMAIL-GUIDE.md).

## 11. SMTP configuration (Gmail)

By default `MAIL_MAILER=log`: each email is written to `storage/logs/laravel.log`. This was verified (both
queued emails appeared in the log with correct To/Subject/body).
**Real Gmail delivery was NOT verified** (no credentials were available).

To send through Gmail, edit `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your.address@gmail.com
MAIL_PASSWORD=your16characterapppassword
MAIL_FROM_ADDRESS="your.address@gmail.com"
MAIL_FROM_NAME="${APP_NAME}"
```

Then:

```powershell
php artisan config:clear
php artisan queue:restart     # then start the worker again
```

**What is a Gmail App Password?** Gmail does not accept your normal account password from apps like this.
You must turn on **2-Step Verification** for the Google account, then create an **App Password**
(Google Account → Security → App passwords). Google shows a 16-character password once; put it in
`MAIL_PASSWORD` without spaces. It only works for mail access and can be revoked at any time.

- Port **587** uses STARTTLS (connection starts plain and upgrades to encryption). Port **465** uses direct SSL;
  for that, set `MAIL_PORT=465` and `MAIL_SCHEME=smtps`.
- Never commit the real password. It belongs only in `.env`.
- If sending fails, the worker retries 3 times (10 seconds apart), then `SendTicketEmail::failed()` writes
  *"Ticket email failed permanently"* to the log and the job appears in `php artisan queue:failed`.

> **বাংলায়:** Gmail সাধারণ পাসওয়ার্ড দিয়ে অ্যাপকে মেইল পাঠাতে দেয় না। 2-Step Verification চালু করে একটা আলাদা
> ১৬ অক্ষরের "App Password" বানাতে হয়, সেটা শুধু `.env`-এর `MAIL_PASSWORD`-এ বসাতে হয়। দরকার হলে এই পাসওয়ার্ড
> আলাদাভাবে বাতিল করা যায়, তাই মূল পাসওয়ার্ড ঝুঁকিতে পড়ে না।

## 12. Cache configuration

- `CACHE_STORE=database` → values are stored in the `cache` table (`key`, `value`, `expiration`).
- The default prefix comes from `config/cache.php`:
  `'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-')`,
  so with `APP_NAME=ResolveDesk` the stored keys look like `resolvedesk-cache-dashboard:admin:stats`.
- Keys used by the app:

| Key | TTL | Defined in | Cleared by |
|---|---|---|---|
| `dashboard:admin:stats` | 60 s | `TicketService::DASHBOARD_CACHE_KEY`, `DashboardController::ADMIN_STATS_TTL_SECONDS` | `TicketService::clearDashboardCache()`, `CategoryController::clearCaches()`, seeder |
| `ticket:active-categories` | 3600 s | `Category::ACTIVE_CACHE_KEY`, `Category::ACTIVE_CACHE_TTL_SECONDS` | `Category::clearActiveCache()` |

- Clear everything manually: `php artisan cache:clear`.
- Tests use `CACHE_STORE=array` (`phpunit.xml`), an in-memory cache that disappears after each test.
- Redis is **not** used (future improvement only).

Details: [Caching guide](15-CACHING-GUIDE.md).

## 13. Testing commands

Tests live in `tests/Feature` (9 files: `AuthTest`, `AuthorizationTest`, `CacheTest`, `NotificationTest`,
`PageRenderingTest`, `QueueAndEmailTest`, `TicketManagementTest`, `TicketWorkflowTest`,
`UserAndCategoryManagementTest`). `phpunit.xml` overrides the environment for tests:

```xml
<env name="APP_ENV" value="testing"/>
<env name="CACHE_STORE" value="array"/>
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="resolvedesk_testing"/>
<env name="MAIL_MAILER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
```

So tests never touch the `resolvedesk` database, never send real email, and run queued jobs immediately.
MariaDB must be running and `resolvedesk_testing` must exist.

| Command | What it does |
|---|---|
| `php artisan test` | Runs the whole suite. The last recorded run in `DEVELOPMENT_PROGRESS.md` passed every test. |
| `php artisan test tests/Feature/CacheTest.php` | Runs one test file. |
| `php artisan test --filter=test_admin_dashboard_creates_the_statistics_cache` | Runs one test method (the filter is a name pattern; it can also match a class name, e.g. `--filter=CacheTest`). |
| `php artisan test --stop-on-failure` | Stops at the first failure — easier to read. |
| `composer test` | `config:clear` + `php artisan test`. |

Always use PHP 8.4 (section 2) before running tests.

## 14. Common setup problems

| Symptom | Cause | Fix |
|---|---|---|
| `Your Composer dependencies require a PHP version ">= 8.4.0"` or `php -v` shows an old version | The old PHP on PATH is being used | Set the temporary PATH (section 2) or use `C:\php-8.4.28\php.exe`. Never use `--ignore-platform-reqs`. |
| `could not find driver` | `pdo_mysql` extension disabled in `C:\php-8.4.28\php.ini` | Enable `extension=pdo_mysql`, open a new terminal, check `php -m`. |
| `SQLSTATE[HY000] [2002] No connection could be made...` | MariaDB/MySQL is not running | Start MySQL in the XAMPP Control Panel. Check port 3306 is not used by another server. |
| `SQLSTATE[HY000] [1049] Unknown database 'resolvedesk'` | Database not created | Create it (section 5.2). |
| `SQLSTATE[HY000] [1045] Access denied` | Wrong `DB_USERNAME`/`DB_PASSWORD` | Fix `.env`, then `php artisan config:clear`. |
| `No application encryption key has been specified.` | `APP_KEY` empty | `php artisan key:generate`. |
| `.env` changes have no effect | Config is cached, or the worker is old | `php artisan config:clear`; `php artisan queue:restart` and start the worker again. |
| `Duplicate entry 'admin@resolvedesk.test'` when seeding | Seeder run on a non-empty database | Use `php artisan migrate:fresh --seed` (**destroys data**) on the local dev DB only. |
| `php artisan db:show` fails mentioning `performance_schema` | MariaDB 10.4 does not provide what the command reads | Harmless; use `migrate:status` or the MariaDB client. |
| Emails never appear | No queue worker running (jobs are waiting in `jobs`) | `php artisan queue:work`; check `php artisan queue:failed` and `storage/logs/laravel.log`. |
| "419 Page Expired" after submitting a form | Session expired or the CSRF token is missing/old | Refresh the page and log in again. Every form must contain `@csrf`. |
| `Attempted to lazy load [relation] on model [...] but lazy loading is disabled` | A relationship is used in a loop without `with()` (protection from `Model::preventLazyLoading()`) | Add the relationship to `with([...])` / `load([...])` in the controller. |
| Admin dashboard numbers look old | Statistics are cached for up to 60 s | Wait, or `php artisan cache:clear`. Normal ticket changes clear it automatically. |
| A test fails with a database error | MariaDB not running, or `resolvedesk_testing` missing | Start MariaDB, create the test database. |
| Times look wrong by several hours | `APP_TIMEZONE` missing | Set `APP_TIMEZONE=Asia/Dhaka`, then `php artisan config:clear`. |
