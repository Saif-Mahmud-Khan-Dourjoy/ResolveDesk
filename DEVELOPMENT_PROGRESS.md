# ResolveDesk — Development Progress

Last updated: 2026-11-25

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
