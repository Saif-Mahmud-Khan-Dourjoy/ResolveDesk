# ResolveDesk — Customer Support & Workflow Automation System

ResolveDesk is a Laravel 13 helpdesk application. Customers report issues as **tickets**, administrators assign
them to **support agents** (manually or automatically by workload), agents work through a controlled
**ticket lifecycle** with **SLA deadlines**, and everyone receives **near-real-time in-app notifications**
plus **queued email updates**.

It is an interview-oriented learning project: the code is deliberately simple, standard Laravel MVC, and every
important part is explained in [`docs/`](docs/).

> Built with AI assistance (Claude Code) as a learning and interview-preparation project.

---

## Business problem

A software company receives support requests through phone, email and chat. Without a central system, issues get
forgotten, engineers duplicate work, workloads are uneven, deadlines are missed and nobody can see the history.

ResolveDesk provides centralized tickets, role-based access, manual and automatic assignment, a controlled
status lifecycle, SLA tracking, customer–agent conversation, a full activity history, automatic notifications,
queued emails and an operational dashboard.

## Features

| Area | What it does |
|---|---|
| Authentication | Session login/logout, password hashing, login rate limiting (5 attempts/min), session regeneration, inactive users blocked even with an existing session |
| Roles | `admin`, `agent`, `customer` (one `role` column) — middleware for role areas, `TicketPolicy` for ticket-level rules |
| Users | List, search, role/status filters, create, view, edit (optional password), activate/deactivate with safety rules |
| Categories | CRUD, unique names, deactivate instead of delete when in use, cached dropdown |
| Tickets | Create, list with search + 7 filters + pagination, details, edit (no status changes), `TK-000001` numbers |
| Workflow | Assign, Auto Assign (least workload), Reassign, Start Working, Resolve (note required), Reopen, Close |
| SLA | Low 48h · Medium 24h · High 8h · Critical 2h from creation; overdue calculated live |
| Conversation & audit | Comments (closed tickets are read-only) and a chronological activity timeline |
| Dashboards | Admin (stats, 3 Chart.js charts, agent workload, avg. resolution time), agent and customer dashboards |
| Notifications | Database notifications, navbar bell with unread badge, latest 5, history page, read/unread, toasts via AJAX polling every 5 s |
| Email & queue | One reusable job + mailable + template, database queue, 3 tries / 10 s backoff, dispatched only after commit |
| Caching | Admin statistics (60 s) and active categories (1 h) with `Cache::remember()` / `Cache::forget()` |

## Technology stack

PHP 8.4.28 · Laravel 13.44 · MySQL-compatible database (developed on **MariaDB 10.4** via XAMPP; MySQL 8 works) ·
Blade · Bootstrap 5.3 · Bootstrap Icons · Vanilla JavaScript (Fetch API) · Chart.js 4 · PHPUnit 12.
Database-backed sessions, cache and queue. Frontend libraries are stored locally in `public/vendor/` (works offline,
no Node.js build step).

## Architecture

Traditional Laravel MVC monolith with one small service class.

```
Browser ─► routes/web.php ─► middleware (auth, active, role) ─► Controller
                                                              │  Gate::authorize() → TicketPolicy
                                                              │  $request->validate()
                                                              ▼
                                                   TicketService (ticket workflow only)
                                                   DB::transaction + row lock
                                                   ├─ Eloquent models → MySQL/MariaDB
                                                   ├─ TicketActivity (audit trail)
                                                   ├─ TicketNotification (database)
                                                   └─ after commit → SendTicketEmail job → jobs table
                                                                          ▲
                                         php artisan queue:work ──────────┘ → TicketUpdateMail
```

Key files: [`app/Services/TicketService.php`](app/Services/TicketService.php),
[`app/Policies/TicketPolicy.php`](app/Policies/TicketPolicy.php),
[`app/Models/Ticket.php`](app/Models/Ticket.php) (all statuses, priorities, SLA hours and transitions),
[`routes/web.php`](routes/web.php), [`public/js/notifications.js`](public/js/notifications.js).

## Installation (Windows)

This PC has an older PHP on the global PATH. **Do not change the permanent PATH**; set it per terminal:

```powershell
# PowerShell
$env:Path = "C:\php-8.4.28;$env:Path"
php -v          # must show 8.4.28
composer -V     # must say "PHP version 8.4.28"
```

```bash
# Git Bash
export PATH="/c/php-8.4.28:$PATH"
```

VS Code already uses PHP 8.4 through [`.vscode/settings.json`](.vscode/settings.json).

1. Start MySQL/MariaDB (XAMPP Control Panel → MySQL → Start).
2. Create the databases:
   ```sql
   CREATE DATABASE resolvedesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE resolvedesk_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Install and configure:
   ```bash
   composer install
   copy .env.example .env        # (cp on Git Bash) then check DB_* values
   php artisan key:generate
   php artisan migrate --seed
   ```

### Database configuration (`.env`)

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=resolvedesk
DB_USERNAME=root
DB_PASSWORD=
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
APP_TIMEZONE=Asia/Dhaka
```

## Running the application

```bash
php artisan serve            # http://127.0.0.1:8000
php artisan queue:work       # second terminal: sends queued emails
```

The app works fully without the queue worker; emails simply wait in the `jobs` table until the worker starts.

| Queue command | Purpose |
|---|---|
| `php artisan queue:work` | Start processing jobs |
| `php artisan queue:work --stop-when-empty` | Process pending jobs then exit |
| `php artisan queue:failed` | List failed jobs |
| `php artisan queue:retry all` | Retry failed jobs |
| `php artisan queue:restart` | Tell running workers to restart after code changes |

With `MAIL_MAILER=log`, emails are written to `storage/logs/laravel.log`. Gmail SMTP setup is in
[docs/02-SETUP-GUIDE.md](docs/02-SETUP-GUIDE.md) (requires a Google App Password; never commit credentials).

## Demo credentials (local demonstration only)

All seeded accounts use the password **`password`**. Never use these outside a local machine.

| Role | Email |
|---|---|
| Administrator | `admin@resolvedesk.test` |
| Support agent | `sarah.agent@resolvedesk.test` (also `david.`, `fatima.`, `michael.agent@resolvedesk.test`) |
| Customer | `john.customer@example.com` (also `emily.`, `rahim.`, `olivia.customer@example.com`) |
| Inactive customer | `tanvir.customer@example.com` — demonstrates that deactivated users cannot sign in |

Tip: open the agent in a private/incognito window and the admin in a normal window to watch notifications
arrive without refreshing.

## Testing

Tests use the separate `resolvedesk_testing` database (configured in [`phpunit.xml`](phpunit.xml)), so the
development data is never touched.

```bash
php artisan test                                  # all tests
php artisan test --filter=TicketWorkflowTest      # one file
php artisan test --filter=test_auto_assign_picks_agent_with_lowest_workload   # one test
```

Latest run: **88 tests, 389 assertions, all passing** (see [DEVELOPMENT_PROGRESS.md](DEVELOPMENT_PROGRESS.md)).

## Documentation

| # | Guide |
|---|---|
| 01 | [Project overview](docs/01-PROJECT-OVERVIEW.md) |
| 02 | [Setup guide](docs/02-SETUP-GUIDE.md) |
| 03 | [Database guide](docs/03-DATABASE-GUIDE.md) |
| 04 | [Laravel MVC guide](docs/04-LARAVEL-MVC-GUIDE.md) |
| 05 | [Module-wise code explanation](docs/05-MODULE-WISE-CODE-EXPLANATION.md) |
| 06 | [Business logic guide](docs/06-BUSINESS-LOGIC-GUIDE.md) |
| 07 | [UI-to-backend mapping](docs/07-UI-TO-BACKEND-MAPPING.md) |
| 08 | [Live coding practice (35+ exercises)](docs/08-LIVE-CODING-PRACTICE.md) |
| 09 | [Interview questions and answers](docs/09-INTERVIEW-QUESTIONS-AND-ANSWERS.md) |
| 10 | [System design guide](docs/10-SYSTEM-DESIGN-GUIDE.md) |
| 11 | [Debugging guide](docs/11-DEBUGGING-GUIDE.md) |
| 12 | [Quick reference](docs/12-QUICK-REFERENCE.md) |
| 13 | [Notification system](docs/13-NOTIFICATION-SYSTEM-GUIDE.md) |
| 14 | [Queue and email](docs/14-QUEUE-AND-EMAIL-GUIDE.md) |
| 15 | [Caching](docs/15-CACHING-GUIDE.md) |
| 16 | [Codebase learning map](docs/16-CODEBASE-LEARNING-MAP.md) |
| 17 | [Demo and interview guide](docs/17-DEMO-AND-INTERVIEW-GUIDE.md) |

Start with **16 (learning map)**, then **07 (UI-to-backend mapping)** and **06 (business logic)**.

## Known limitations

- Notifications use **AJAX polling** (every 5 s, 30 s in hidden tabs) — near-real-time, not server push.
- Auto-assignment locks the ticket row, but two *different* tickets auto-assigned at the same moment may both
  pick the same least-busy agent. This is application-level workload distribution, not infrastructure load balancing.
- SLA uses continuous calendar hours (no business hours/holidays). Reopened tickets keep their original deadline,
  so they can be overdue immediately (deliberate simplification). No scheduled SLA escalation.
- Average resolution time measures creation → latest resolution, even if the ticket was reopened.
- Resolved tickets cannot be reassigned, so an agent who still owns resolved tickets can only be deactivated after
  those tickets are closed (an admin can close them).
- Queue retries can occasionally send a duplicate email (no idempotency tracking).
- The database cache still queries the database; it only saves the expensive aggregate queries.
- `TIMESTAMPDIFF` / `DATE()` in the dashboard are MySQL/MariaDB functions (SQLite is not supported).
- Developed and tested on MariaDB 10.4 because MySQL 8 is not installed on the development PC.
- Visual browser rendering and toast pop-ups were checked through HTTP/feature tests only, not with browser
  automation; Gmail SMTP delivery was not tested (log mailer used).

## Security notes

CSRF tokens on every form and AJAX POST, escaped Blade output (`{{ }}`) and `textContent` in JavaScript (XSS),
Eloquent parameter binding (SQL injection), `$fillable` mass-assignment protection, server-side validation,
backend authorization on every action (policies + middleware), foreign-key constraints, users/tickets are never
hard-deleted. `.env` is git-ignored. For production set `APP_ENV=production`, `APP_DEBUG=false`, a strong DB
password, real SMTP credentials via environment variables, and run the queue worker under a process manager.
