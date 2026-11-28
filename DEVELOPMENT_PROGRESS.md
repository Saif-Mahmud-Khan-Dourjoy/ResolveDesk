# ResolveDesk — Development Progress

Last updated: 2026-11-26 (V2 phases 0-8 done + production-readiness audit)

---

# Production-readiness audit (2026-11-26)

Full report: `docs/PRODUCTION-READINESS-REPORT.md`. Study order: `docs/CODEBASE-LEARNING-MAP.md`.

Verdict: **ready for a controlled pilot deployment only after** B-1 MySQL 8 test run, B-2 Linux/Nginx/HTTPS/WSS
staging deployment, B-3 production `.env`, B-4 backup-restore drill, B-5 two-browser real-time click-through.

Fixed (each with a regression test that failed before the fix):
- A-1 (High) services re-check `TicketPolicy` on the LOCKED ticket row (`TicketService::authorizeOnCurrentState()`):
  a just-reassigned agent could still resolve/comment with a request that omitted `version`.
- A-2 (Medium) deactivate/demote agent vs. assign race: `UserController::update()/toggleActive()` now transaction +
  `lockUser()`; last-admin count locked; assignment re-reads the agent `FOR UPDATE`. Reproduced with a second PHP
  process holding an uncommitted transaction (`MySqlConcurrencyTest`, 2 new tests).
- A-3 (Medium) SLA reminder no longer increments `version` (removed false 409s on overdue tickets).
- A-4 (Medium) edit form after a 409 now carries the current version (was an endless conflict loop).
- A-5 (Medium) Symfony 8.1.10/11 -> 8.1.12 patch update for 5 advisories; `composer audit` clean, `npm audit` 0.

Bangla guides updated for the fixes: `docs/ResolveDesk-Project-Explanation-Bangla.docx` (new section 29 + corrected
§22/23/26/28, now 43 pages) and `docs/ResolveDesk-Process-Guide-UI-to-Database-Bangla.docx` (P7, P9, P10, P13, P14,
P17, P22, security table; 32 pages). Both pass the OOXML schema validation and were opened/rendered with Word.

Verification actually performed:
- `php artisan test`: baseline 164 passed (661); after fixes **170 passed (682 assertions)** on MariaDB 10.4 (`resolvedesk_testing`).
- New isolated DB `resolvedesk_audit` (migrated + seeded); 4 separate `php -S` processes, database cache/queue:
  8 parallel same-token creations -> 1 ticket/1 activity/1 notification; 4 parallel assigns same version -> 1 win,
  3x409; 4 parallel auto-assigns -> serial least-workload result, 0 leftover locks; close vs reopen -> one wins;
  2 parallel `tickets:check-sla` -> 6 reminders for 6 tickets, no duplicates; queue worker 14 jobs, 0 failed.
- Real Reverb + Node pusher-js with 3 logged-in sessions: 14/14 checks (foreign/guest channel auth refused, event in
  254 ms, payload id only, internal note never reached the customer, public reply did). Reverb stopped -> assignment
  still succeeded and the polling feed returned the notification.
- Dev DB `resolvedesk` row counts identical before/after (not touched).
- NOT verified: MySQL 8, Linux deployment, HTTPS/WSS, Supervisor/cron, SMTP, browser UI automation, restore drill, load tests.

---

# V2 upgrade (Multi-Project Customer Support) — status

Specification: `V2_MASTER_PROMPT.md` (supersedes conflicting V1 rules; all other V1 rules stay).

## Phase 0 — Baseline (done 2026-11-26)

| Check | Result |
|---|---|
| Git | branch `dev`, clean tree, 1 commit (`e28ea3c initial Structure and business logic`), remote `origin` (GitHub) |
| PHP / Composer | 8.4.28 (`C:\php-8.4.28\php.exe`), Composer 2.8.8 on PHP 8.4.28 |
| Laravel | 13.44.1 |
| Node / npm | v20.19.5 / 10.8.2 (registry reachable) — needed only for the Echo bundle (Vite) |
| Database | XAMPP MariaDB 10.4.27 (MySQL 8 not installed); InnoDB row locks behave like MySQL 8 for `FOR UPDATE` |
| Dev data | `resolvedesk`: 10 users, 34 tickets (TK-000033/34 created by the owner), 73 notifications — **must be preserved** |
| Backup | `storage/app/backups/resolvedesk-before-v2-2026-11-26.sql` (mysqldump, git-ignored) taken before any V2 migration |
| Baseline tests | `php artisan test` → **88 passed (389 assertions)** in 38.5 s |
| Composer access | `laravel/reverb` v1.14.0 available (supports Laravel 13) |

Existing V1 features confirmed in code: auth + rate limit, role middleware, TicketPolicy, user/category CRUD,
TicketService (create/update/assign/auto-assign/start/resolve/reopen/close/comment) with transactions and
`lockForUpdate`, database notifications + AJAX polling, queued email after commit, admin dashboard cache,
category cache, seeders, 9 feature test files.

Technical issues found at baseline (to fix in V2):
- `updateTicket()` has no lock and no version check (lost update possible).
- No idempotency for ticket creation (double submit = two tickets).
- Auto-assign of two different tickets can pick the same agent (no coordination lock).
- `ticket_activities.user_id` is NOT NULL, so system (scheduler) activities cannot be recorded.
- Admin `assigned_to` list filter is not validated.
- `package.json` / `vite.config.js` are Laravel defaults (Tailwind + axios) and unused.

## V2 implementation plan

Migration strategy: **only additive, incremental migrations** (`php artisan migrate`, never `migrate:fresh`
on the dev DB). All new ticket columns are nullable or have defaults, so the 34 existing tickets become
**global tickets** (`project_id = NULL`, `client_id = NULL`); no client/project mapping is invented.
Rollback: each migration has a `down()`; rolling back V2 columns drops V2 data only (documented).

| Phase | Scope | Status |
|---|---|---|
| 0 | Baseline, backup, plan | Done |
| 1 | `projects`, `clients`, `users.client_id`, `client_project`, `project_user`; Project/Client CRUD + association UI with removal guards | Done — 102 tests passing (14 new in `ProjectAndClientTest`) |
| 2 | Ticket `project_id`/`client_id`, scoped creation per role, dependent dropdown JSON endpoint, project-aware assignment eligibility, filters | Done — `TicketScopeTest` (17 tests) |
| 3 | `version` (optimistic locking, 409), version bump on every mutation, `Cache::lock` assignment lock, deadlock retries, `request_token` idempotency | Done — `ConsistencyTest` (11 tests); suite 130 passed / 547 assertions |
| 4 | Reverb + Echo private `users.{id}` channel, broadcast after commit, polling fallback, previous-agent notification, customer-reply email, retry_after/timeout | Done — `RealtimeAndDeliveryTest` (8) + live Reverb E2E check |
| 5 | Internal notes, secure private attachments, SLA command + scheduler (`sla_notified_at`), dashboards, reports + CSV export | Done — `InternalNotesAndAttachmentsTest` (11), `SlaReportsAndHardeningTest` (12) |
| 6 | Rate limiters, request-id logging context, daily logs, readiness endpoint, indexes, 429 error page (409 = JSON or redirect back with input, no page) | Done (daily logs documented for production; local stays `single`) |
| 7 | Tests (feature + MySQL concurrency), regression, smoke test | Done — **164 passed / 661 assertions**; `MySqlConcurrencyTest` (3, two connections); multi-process checks (see below) |
| 8 | Docs: update docs/01-17, add deployment, backup/recovery, concurrency, realtime, attachments/SLA guides | Done — docs 01-17 updated, 18-23 added, README rewritten |

Phase 1-3 notes (2026-11-26):
- Migrations `2026_11_26_000001..000007` applied to the dev DB with `php artisan migrate` (additive). Tested first on a
  restored copy (`resolvedesk_v2check`): migrate -> rollback -> migrate all succeeded, 34 tickets intact; copy dropped.
  Rollback bug found and fixed: the `(client_id,status)` index backs a foreign key, so `down()` drops FKs first.
- Service split: `TicketService` (create/edit/comments/lifecycle/SLA), `TicketAssignmentService` (assign/auto-assign,
  cache lock), `TicketNotifier` (database notification + after-commit broadcast + after-commit email),
  `AttachmentService` (private files), `ProjectService` (association changes with row lock).
- Existing tests changed only where V2 intentionally changed behaviour: ticket creation now requires `request_token`,
  edits require `version`, customer replies email the agent, reassignment notifies the previous agent, auto-assign
  message mentions eligibility.

Phase 4-7 notes (2026-11-26):
- `composer require laravel/reverb:^1.14` (v1.14.0), `php artisan install:broadcasting --reverb --without-node`.
  The installer appended `BROADCAST_CONNECTION=reverb` but did not create Reverb keys, which broke the broadcaster;
  fixed by hand: one `BROADCAST_CONNECTION=reverb`, random `REVERB_APP_ID/KEY/SECRET` in `.env` (never printed),
  placeholders in `.env.example`, `config/reverb.php` published, broadcaster connect/timeout 2 s / 3 s.
- npm: unused Laravel defaults (Tailwind, axios, concurrently, resources/css/app.css, resources/js/app.js,
  bootstrap.js, echo.js) removed. Only `laravel-echo`, `pusher-js`, `vite`, `laravel-vite-plugin` remain;
  `npm audit`: 0 vulnerabilities. Vite builds ONLY `resources/js/realtime.js`. The layout loads it only when
  `public/build/manifest.json` exists, so the app still works (polling) without `npm run build`.
- Dev DB: migrations 000001-000008 applied (additive). `V2DemoDataSeeder` run on the dev DB: 5 projects,
  3 clients, 6 client-project and 8 project-agent links. Existing users/tickets NOT changed (all 34 tickets stay global,
  no customer was linked to a client - link them through the UI if wanted).
- Index migration 000008: notifications `(notifiable_type, notifiable_id, created_at)` replaced the 2-column index;
  EXPLAIN before = "Using filesort", after = index used, no filesort.
- Pint added (`pint.json`: Laravel preset + `concat_space: one` to keep the existing `'a' . 'b'` style);
  run only on changed files (`vendor/bin/pint --dirty`).
- Line endings: Windows Python edits had produced CRLF; all changed files normalized back to LF (`.gitattributes eol=lf`).

V2 verification actually performed (2026-11-26):
- `php artisan test` -> **164 passed (661 assertions)** on MariaDB 10.4 (`resolvedesk_testing`).
- Migrations tested on a restored copy of the dev data: migrate -> rollback -> migrate OK, data intact.
- Live real-time E2E (Reverb running, app served by `php -S` on an isolated DB copy, Node + pusher-js client
  logged in as an agent through real HTTP): subscribing to another user's channel refused (AuthError);
  own channel subscribed; admin assignment over HTTP -> `notification.created` received after **255 ms**;
  payload keys = `notification_id` only; the authorized feed contained that id.
- Reverb down: `test_failed_broadcast_does_not_break_the_operation` (real connection refusal) -> assignment
  succeeds, notification persisted. Browser polling fallback logic itself verified only by code review.
- Multi-process concurrency (6 / 4 separate PHP processes started at the same instant, database cache store):
  6 parallel creations with the same request token -> 1 ticket, 1 `created` activity, 5 replays;
  4 parallel auto-assigns of different tickets -> Michael x2, Fatima x1, David x1 (exactly the sequential
  least-workload result), 0 locks left in `cache_locks`.
- NOT verified: visual check in a real browser (no browser automation), toast rendering via WebSocket in a browser,
  Gmail SMTP, Linux/Nginx deployment, backup restore drill, MySQL 8 (only MariaDB 10.4 available).

Fixes made after the documentation review (all tests still 164 passed / 661 assertions):
- `TicketService::resolveScope()` now takes the project shared lock as the FIRST query (closes a narrow
  REPEATABLE-READ snapshot race with a concurrent client removal).
- Idempotency replay comparison also compares `project_id`.
- `config/reverb.php` allowed origins read from `REVERB_ALLOWED_ORIGINS`; `.env.example` gained
  `REVERB_SERVER_HOST/PORT`, `REVERB_ALLOWED_ORIGINS`, commented `SESSION_SECURE_COOKIE`.
- `SendTicketEmail::failed()` no longer logs the recipient's email address.
- Ticket list Project filter for non-admins lists only projects of tickets they can see (no product-name leak).
- `composer dev` starts serve + queue + reverb + scheduler; `concurrently` restored as npm dev dependency.
- Outdated docblocks (TicketNotifier, SendTicketEmail, TicketNotification, ProjectService, migration 000007) corrected.
- New `docs/ResolveDesk-Process-Guide-UI-to-Database-Bangla.docx` (31 pages): 22 business processes, each UI -> route -> middleware -> controller -> policy -> service -> database (with SQL and before/after rows), plus table/rule/error/security reference.
- `docs/ResolveDesk-Project-Explanation-Bangla.docx` regenerated for V2 (40 pages: Part 1 V1 flow corrected + Part 2 sections 18-28).

Known V2 limitations:
- Timestamps stored in Asia/Dhaka (not UTC) to avoid rewriting V1 data.
- Reverb single server, no Redis scaling; several browser tabs each open a WebSocket and each shows the toast.
- Assignment lock is global (one assignment at a time) and time-limited (10 s); it coordinates workload, the ticket
  row lock + status/version checks remain the real guarantee.
- Email de-duplication uses the cache (2 days); a crash between SMTP accept and cache write can still duplicate.
- Resolved tickets cannot be reassigned (V1 rule), so an agent with Resolved project tickets can only be removed
  from that project / deactivated after those tickets are closed.
- No antivirus scanning of attachments; no attachment deletion UI; files are not removed if a ticket is deleted
  (tickets are never deleted).
- Customer organisation can't change after they created client tickets (create a new account instead).
- Project/client associations are edited only on the project page.
- No self-service password reset: administrators replace passwords on the user edit page (allowed by V2 spec §7).
- Trusted proxies are not configured (fine for Nginx + PHP-FPM on one host; needed behind a load balancer/CDN).
- Workflow forms always send `version`, but the controllers accept a missing version (no stale-intent check) for
  hand-made requests. Since the audit (A-1) this can no longer bypass authorization: the policy is re-checked on the locked row.
- (Fixed in the audit, A-3) The SLA scheduler no longer increments `version`.
- Rolling back migration 000006 would make internal notes visible to customers (and fails once system activities exist).
- Login lockout shows a validation message, not the 429 page. Reverb origins default to `*` locally; set
  `REVERB_ALLOWED_ORIGINS` in production (added to `config/reverb.php` and `.env.example` after the docs review).

Key design decisions (V2):
- **Timezone**: timestamps stay stored in `Asia/Dhaka` (V1 data is stored that way). Converting existing
  rows to UTC would rewrite historical data, so it is documented as a known deviation from the
  "preferably UTC" recommendation.
- **Visibility rule unchanged**: agent/customer see tickets they created or are assigned to. Project
  membership only controls assignment eligibility (spec §6).
- **Lock order** (deadlock prevention): assignment cache lock → project row → ticket row.
- **Broadcasting**: `ShouldBroadcastNow` event dispatched in `DB::afterCommit()` with try/catch, carrying
  only the notification id. If Reverb is down the request still succeeds and polling picks the data up.
- **Association management** lives on the project details page (clients + agents checklists), one place for
  the removal rules; user and client pages show the associations read-only.

## Environment (verified)

| Item | Value |
|---|---|
| PHP | 8.4.28 at `C:\php-8.4.28\php.exe` (loaded ini: `C:\php-8.4.28\php.ini`) |
| Composer | 2.8.8, running on PHP 8.4.28 |
| Laravel | 13.44.1 (existing skeleton was reused, not reinstalled) |
| Database | **MariaDB 10.4.27** from XAMPP (MySQL 8.x is NOT installed on this PC) |
| Dev DB | `resolvedesk` (root, empty password - XAMPP default) |
| Test DB | `resolvedesk_testing` (set in `phpunit.xml`, isolated from dev data) |
| Timezone | `APP_TIMEZONE=Asia/Dhaka` (config/app.php reads it) |

Start MariaDB: XAMPP Control Panel → MySQL → Start (or `C:\xampp\mysql_start.bat`).

Use PHP 8.4 in each new terminal:

```bash
export PATH="/c/php-8.4.28:$PATH"     # Git Bash
$env:Path = "C:\php-8.4.28;$env:Path" # PowerShell
```

## Phase status

| Phase | Status |
|---|---|
| 1. Environment inspection | Done |
| 2. Database and models | Done |
| 3. Authentication and shared UI | Done |
| 4. Users and categories | Done |
| 5. Ticket management | Done |
| 6. Business workflow | Done |
| 7. Notifications | Done |
| 8. Queue and email | Done and verified (worker + mail log) |
| 9. Dashboard and caching | Done |
| 10. Seeders, tests, UI refinement | Done - 88 tests / 389 assertions passing |
| 11. Documentation | Done - README.md + docs/01..17 |
| 12. Final review | Done (see "Final review fixes" below) |

## Verification performed (2026-11-25)

- `php artisan test` → **88 passed (389 assertions)** against `resolvedesk_testing` (MariaDB).
  Files: AuthTest, AuthorizationTest, TicketManagementTest, TicketWorkflowTest, NotificationTest,
  QueueAndEmailTest, CacheTest, UserAndCategoryManagementTest, PageRenderingTest (seeds demo data, opens every page per role).
- Real HTTP smoke test with `php artisan serve` + curl, separate cookie sessions for admin / agent / customer / inactive user:
  logins, all main pages 200, inactive user redirected to login, customer 403 on /users and on another customer's ticket,
  admin assign → agent's `/notifications/feed?since=` returned the new notification, AJAX mark-all-read with
  X-CSRF-TOKEN worked, POST without token → 419, auto-assign picked the least-busy agent (Michael Brown, workload 2).
- With no worker running, 2 `SendTicketEmail` jobs waited in `jobs`; `php artisan queue:work --stop-when-empty`
  processed both (0 pending, 0 failed) and `storage/logs/laravel.log` contained both emails (To/Subject/body correct).
- NOT verified: visual rendering in a real browser (no browser automation available), the JS toast popping up
  in a browser, and Gmail SMTP delivery (no credentials; log mailer used).

## Bugs found and fixed during verification

1. `due_at` overwritten on every UPDATE (MariaDB implicit `ON UPDATE CURRENT_TIMESTAMP`) → columns changed to DATETIME.
2. Blade compiled `@json()` written inside a JavaScript comment → comment now uses `@@json`.
3. Multi-line `@include([...])` with a ternary failed to compile → value precomputed with `@php`.

## Final review fixes (after documentation review)

4. **Category cache returned `__PHP_Incomplete_Class`** with `CACHE_STORE=database` (Laravel 13 sets
   `cache.serializable_classes => false`), so `/tickets/create` and `/tickets/{id}/edit` failed on the 2nd request.
   Tests missed it because phpunit uses the `array` store. Fix: `Category::activeForDropdown()` caches a plain array
   and returns `Category::hydrate($rows)`. New regression test uses the real database store.
5. Notification feed: `server_time` captured before the queries, and `?since=` looks back 10 extra seconds
   (`SINCE_OVERLAP_SECONDS`) so a notification committed just after a poll still gets a toast (JS de-duplicates by ID).
6. Ticket list filters and feed `since` are validated (invalid dates → validation error / 422 instead of SQL weirdness or 500).
7. Ticket edit uses `concat()` instead of `push()` so the cached category collection is never mutated.

## Known limitations (also in README)

- `updateTicket()` does not lock the row (last write wins on simultaneous edits; low impact).
- Admin `assigned_to` list filter is not in the validation list (safe: bound parameter, admin only).
- Agent dashboard stat cards count assigned tickets, but their links open the ticket list, which also shows tickets the agent created.
- Last-active-admin rule is unreachable through the UI (self-protection rule fires first); kept as a safety net.
- Concurrent auto-assign of different tickets may choose the same agent; polling latency up to 5 s; duplicate email possible on retry.
- No Xdebug installed in C:\php-8.4.28 (debugging guide lists optional setup steps).
- Dev DB was reset with `migrate:fresh --seed` (it only ever contained demo seed data created in this session).

## How to resume

Everything in MASTER_PROMPT phases 1-12 is implemented. For changes: edit code, run `php artisan test`,
update the matching docs (docs/07 maps UI → code; docs/12 is the cheat sheet).

## Important decisions

- **MariaDB instead of MySQL 8**: only XAMPP MariaDB 10.4 exists locally. All code uses Eloquent/standard SQL;
  the only DB-specific SQL is `TIMESTAMPDIFF` and `DATE()` in `DashboardController` (both work on MySQL 8 and MariaDB).
- **`due_at` / `resolved_at` are DATETIME, not TIMESTAMP**: on MariaDB/old MySQL the first NOT NULL TIMESTAMP column
  gets an implicit `ON UPDATE CURRENT_TIMESTAMP`, which silently overwrote the SLA deadline. Found during seeding.
- Allowed values are constants on models: `User::ROLES`, `Ticket::STATUSES`, `Ticket::PRIORITIES`,
  `Ticket::SLA_HOURS`, `Ticket::TRANSITIONS` (keyed by action: start/resolve/close/reopen).
- `TicketService` holds all coordinated ticket rules (create/update/assign/auto-assign/start/resolve/reopen/close/comment).
  Plain CRUD (users, categories) stays in controllers.
- Business-rule failures throw `ValidationException` → redirect back with an error (422 JSON for AJAX).
- Emails: `DB::afterCommit()` + try/catch around `SendTicketEmail::dispatch()` (rollback = no job; dispatch failure = logged only).
- In-app notifications are NOT queued (database channel, inside the transaction) → work without a queue worker.
- Notification polling: `GET /notifications/feed?since=<server_time>`; toasts de-duplicated by notification ID in JS.
- `Model::preventLazyLoading()` outside production to catch N+1 queries.
- Users table: removed unused `email_verified_at`; removed unused `password_reset_tokens` and `job_batches` tables.
- Ticket number: insert, then `TK-` + zero-padded id, in the same transaction (column is nullable + unique).
- Edit form: priority change recalculates `due_at` from original `created_at` and logs `priority_changed` + `due_date_changed`.
- Agent deactivation/demotion blocked while they have Assigned/In Progress/Resolved tickets.
  Resolved tickets cannot be reassigned (spec), so an admin must close them first.
- Seeder includes an inactive customer (`tanvir.customer@example.com`) and an inactive category ("Legacy Desktop App").

## Demo credentials (local only, password `password`)

- Admin: `admin@resolvedesk.test`
- Agent: `sarah.agent@resolvedesk.test` (also david/fatima/michael `.agent@resolvedesk.test`)
- Customer: `john.customer@example.com` (also emily/rahim/olivia; tanvir is inactive)

## Next steps

1. Feature tests (tests/Feature) and run them.
2. Real HTTP smoke test with `php artisan serve`; queue worker + mail log verification.
3. README + docs/01..17.
4. Final review.

## Change log

- 2026-11-26 — **New ticket → admin notification** (requested by owner, beyond the original §9.2 matrix):
  `TicketService::createTicket()` notifies every active admin (in-app + queued email, event type `created`);
  the admin who created the ticket and inactive admins are skipped (`notifyUser()` rules). Seeder adds matching
  admin notifications. Tests: `QueueAndEmailTest::test_new_ticket_notifies_and_emails_every_active_admin`,
  `test_admin_creating_a_ticket_is_not_notified_about_it`. Suite: 88 passed (389 assertions).
  Note: XAMPP MariaDB must be running for tests (a stopped DB makes `php artisan test` hang instead of failing fast).
