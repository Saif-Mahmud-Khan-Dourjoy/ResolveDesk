# ResolveDesk — Multi-Project Customer Support & Workflow Automation System

ResolveDesk is a Laravel 13 helpdesk for a software company that supports **several products (projects)**
used by **several client organizations**. Customers report issues as **tickets**; administrators assign them to
**support agents** (manually or automatically by workload, limited to agents who support the project); agents work
through a controlled **ticket lifecycle** with **SLA deadlines**; everyone receives **real-time notifications**
(WebSockets via Laravel Reverb, with AJAX polling as fallback) plus **queued email updates**.

It is an interview-oriented learning project: standard Laravel MVC as a small modular monolith, and every important
part is explained in [`docs/`](docs/).

> Built with AI assistance (Claude Code) as a learning and interview-preparation project.

---

## Business problem

A software company sells HRMS, ERP, Payroll and similar products to many client companies. Requests arrive by phone,
email and chat; without a central system issues get forgotten, engineers duplicate work, workloads are uneven,
deadlines are missed, one client's data can leak to another, and nobody can see the history.

ResolveDesk provides centralized tickets grouped by project and client, logical client data isolation, role-based
access, eligibility-aware manual and automatic assignment, a controlled lifecycle, SLA tracking with scheduled breach
reminders, public replies and staff-only internal notes, secure attachments, a full activity history, real-time
notifications, queued emails, dashboards and CSV reports.

## Features

| Area | What it does |
|---|---|
| Authentication | Session login/logout, hashing, login rate limit (5/min), session regeneration, inactive users blocked even with an existing session |
| Roles | `admin`, `agent`, `customer` — middleware for role areas, `TicketPolicy` for ticket-level rules |
| Projects & clients (V2) | CRUD, activate/deactivate (never deleted), project↔client and project↔agent associations with removal guards |
| Users | Search/filter, customer ↔ client organization, safety rules for deactivation/demotion and organization changes |
| Ticket scope (V2) | Global or project ticket × internal or client ticket; scope rules per role, enforced on the server |
| Tickets | Create (with attachments), list with search + 10 filters, details, edit (optimistic locking), `TK-000001` numbers |
| Workflow | Assign, Auto Assign (least workload among eligible agents), Reassign, Start, Resolve, Reopen, Close |
| Data integrity (V2) | Transactions with deadlock retry, row locks, `version` column (409 on stale data), global assignment `Cache::lock`, idempotent creation (`request_token`) |
| Conversation | Public replies + staff-only internal notes, private attachments (5 MB, 5 files, type-checked) |
| SLA | Low 48h · Medium 24h · High 8h · Critical 2h; remaining time, within/after SLA; `tickets:check-sla` every minute, one reminder per breach |
| Notifications | Database notifications (source of truth), Reverb private channel `users.{id}`, polling fallback, toasts |
| Email & queue | One job + mailable + template, after-commit dispatch, 3 tries / 10 s backoff / 60 s timeout, duplicate-send guard |
| Dashboards & reports | Role dashboards, project/client/SLA-compliance stats, report page + streamed CSV export (formula-injection safe) |
| Operations | Named rate limiters, request id in logs (`X-Request-Id`), `/up` liveness and `/health/ready` readiness |

## Technology stack

PHP 8.4.28 · Laravel 13.44 · MySQL-compatible database (developed on **MariaDB 10.4** via XAMPP; MySQL 8 compatible) ·
Blade · Bootstrap 5.3 · Vanilla JavaScript (Fetch API) · Chart.js 4 · Laravel Reverb 1.14 + Laravel Echo 2 (built with
Vite — only `resources/js/realtime.js`) · database queue, cache, sessions · Laravel Scheduler · PHPUnit 12 · Pint.
Bootstrap, icons and Chart.js are local files in `public/vendor/` (no build step for them).

## Architecture

```
Browser ─► routes/web.php ─► middleware (auth, active, role, throttle) ─► Controller
                                                                         │ Gate::authorize() → TicketPolicy
                                                                         │ validation (inline / StoreTicketRequest)
                                                                         ▼
          TicketService · TicketAssignmentService · ProjectService · AttachmentService
          DB::transaction(..., 3) + lockForUpdate + version check (+ Cache::lock for assignment)
          ├─ Eloquent → MySQL/MariaDB (tickets, activities, notifications, attachments meta)
          └─ TicketNotifier ── after COMMIT ─┬─► UserNotificationCreated ─► Reverb ─► Echo (private users.{id})
                                             └─► SendTicketEmail job ─► jobs table ─► queue:work ─► mail
php artisan schedule:run ─► tickets:check-sla ─► TicketService::processSlaBreach()
```

## Installation (Windows)

This PC has an older PHP on the global PATH. **Do not change the permanent PATH**; set it per terminal:

```powershell
$env:Path = "C:\php-8.4.28;$env:Path"   # PowerShell
php -v; composer -V                      # both must report PHP 8.4.28
```

```bash
export PATH="/c/php-8.4.28:$PATH"        # Git Bash
```

1. Start MySQL/MariaDB (XAMPP Control Panel → MySQL → Start).
2. Create `resolvedesk` and `resolvedesk_testing` databases (utf8mb4).
3. Install and configure:
   ```bash
   composer install
   npm ci && npm run build          # real-time bundle (optional: without it the app falls back to polling)
   copy .env.example .env           # then set DB_* and generate Reverb values (php artisan reverb:install)
   php artisan key:generate
   php artisan migrate --seed       # fresh database only
   ```
   Upgrading an existing V1 database: back it up, then `php artisan migrate` (8 additive V2 migrations; existing
   tickets become global tickets). Optional demo projects/clients: `php artisan db:seed --class=V2DemoDataSeeder`.

## Running locally (four terminals)

```bash
php artisan serve           # http://127.0.0.1:8000
php artisan queue:work      # emails
php artisan reverb:start    # WebSocket server on :8080 (real-time toasts)
php artisan schedule:work   # runs tickets:check-sla every minute
```

Everything keeps working if the worker, Reverb or scheduler is stopped: emails wait in `jobs`, notifications fall back
to polling, SLA reminders are sent at the next scheduler run. With `MAIL_MAILER=log`, emails go to
`storage/logs/laravel.log`.

| Command | Purpose |
|---|---|
| `php artisan queue:failed` / `queue:retry all` | Inspect / retry failed jobs |
| `php artisan tickets:check-sla` | Run the SLA breach check once |
| `php artisan schedule:list` | Show scheduled tasks |
| `vendor/bin/pint --dirty` | Format changed PHP files |

## Demo credentials (local demonstration only)

All seeded accounts use the password **`password`**. Never use these outside a local machine.

| Role | Email |
|---|---|
| Administrator | `admin@resolvedesk.test` |
| Support agent | `sarah.agent@resolvedesk.test` (HRMS, PAYROLL) · `david.` (ERP, HRMS) · `fatima.` (PAYROLL, ACCT) · `michael.agent@resolvedesk.test` (ERP, INV) |
| Customer (ACME) | `john.customer@example.com`, `emily.customer@example.com` |
| Customer (GLOBEX) | `rahim.customer@example.com` |
| Standalone customer | `olivia.customer@example.com` (global tickets only) |
| Inactive customer | `tanvir.customer@example.com` |

Client/project links for customers exist only in a freshly seeded database; the existing development database was
not changed (its customers stay standalone, its tickets stay global).

## Testing

Tests use the separate `resolvedesk_testing` database ([`phpunit.xml`](phpunit.xml)); XAMPP MySQL must be running.

```bash
php artisan test                                  # all tests
php artisan test --filter=ConsistencyTest         # one file
```

Latest run (2026-11-26 production-readiness audit): **170 tests, 682 assertions, all passing** — including
two-process MySQL race tests. Multi-process HTTP concurrency and a live Reverb end-to-end check were also run; see
[docs/PRODUCTION-READINESS-REPORT.md](docs/PRODUCTION-READINESS-REPORT.md) for what was and was not verified.
Study order for the source code: [docs/CODEBASE-LEARNING-MAP.md](docs/CODEBASE-LEARNING-MAP.md).

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
| 08 | [Live coding practice](docs/08-LIVE-CODING-PRACTICE.md) |
| 09 | [Interview questions and answers](docs/09-INTERVIEW-QUESTIONS-AND-ANSWERS.md) |
| 10 | [System design guide](docs/10-SYSTEM-DESIGN-GUIDE.md) |
| 11 | [Debugging guide](docs/11-DEBUGGING-GUIDE.md) |
| 12 | [Quick reference](docs/12-QUICK-REFERENCE.md) |
| 13 | [Notification system](docs/13-NOTIFICATION-SYSTEM-GUIDE.md) |
| 14 | [Queue and email](docs/14-QUEUE-AND-EMAIL-GUIDE.md) |
| 15 | [Caching](docs/15-CACHING-GUIDE.md) |
| 16 | [Codebase learning map](docs/16-CODEBASE-LEARNING-MAP.md) |
| 17 | [Demo and interview guide](docs/17-DEMO-AND-INTERVIEW-GUIDE.md) |
| 18 | [Production deployment](docs/18-PRODUCTION-DEPLOYMENT.md) |
| 19 | [Backup and recovery](docs/19-BACKUP-AND-RECOVERY.md) |
| 20 | [Concurrency and data integrity](docs/20-CONCURRENCY-AND-DATA-INTEGRITY.md) |
| 21 | [Real-time: Reverb and Echo](docs/21-REALTIME-REVERB-ECHO.md) |
| 22 | [V2 business features](docs/22-V2-BUSINESS-FEATURES.md) |
| 23 | [Security, errors, logging, operations](docs/23-SECURITY-ERRORS-LOGGING-OPERATIONS.md) |

Bangla Word guides: [project explanation](docs/ResolveDesk-Project-Explanation-Bangla.docx) and
[full process guide, UI → backend → database](docs/ResolveDesk-Process-Guide-UI-to-Database-Bangla.docx).

Start with **16 (learning map)**, then **22 (V2 features)**, **07 (UI-to-backend mapping)** and **20 (concurrency)**.

## Known limitations

- Developed and tested on MariaDB 10.4 (MySQL 8 not installed); no real-browser visual test, no Linux deployment,
  no SMTP delivery and no backup-restore drill were performed.
- Timestamps are stored in `Asia/Dhaka`, not UTC (V1 data was stored that way; rewriting it was avoided).
- Single Reverb server; several open tabs each show the toast. Polling fallback latency is up to 5 s.
- The assignment lock is global and time-limited; it coordinates workload, while row locks and status/version checks
  protect each ticket.
- Email de-duplication is best effort (queues are at-least-once; SMTP has no exactly-once).
- Resolved tickets cannot be reassigned, so an agent with Resolved tickets must have them closed before removal or
  deactivation. A customer's organization cannot change after they created client tickets.
- No antivirus scanning of uploads; SLA uses calendar hours; reopened tickets keep their original deadline.

## Security notes

CSRF on all forms/AJAX, escaped Blade output and `textContent` (XSS), Eloquent bindings (SQL injection), explicit
`$fillable`, server-side validation and policies on every action, private broadcast channels, private attachment
disk with authorized downloads, CSV formula-injection protection, rate limiting, request-id logging without secrets.
`.env` is git-ignored. Production configuration: [docs/18](docs/18-PRODUCTION-DEPLOYMENT.md) and [docs/23](docs/23-SECURITY-ERRORS-LOGGING-OPERATIONS.md).
