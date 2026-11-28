# ResolveDesk V2 — Production Readiness Report

Audit date: **2026-11-26** · Branch: `dev` · Specification: `V2_MASTER_PROMPT.md` (+ non-conflicting `MASTER_PROMPT.md` rules)

This report records what was inspected, what was **actually executed**, what was fixed, and what is still unverified.
Nothing below is marked "verified" unless a command, test or HTTP request was really run during this audit.

---

## 1. Verdict

**Ready for a controlled (pilot) production deployment — with conditions. Not unconditionally production-ready.**

The application logic, authorization, data-isolation, concurrency and notification paths held up under real
multi-process tests, and every confirmed defect found in this audit was fixed and covered by a regression test.
What is missing is **environment evidence**, not code:

| # | Blocking condition before go-live | Why |
|---|---|---|
| B-1 | Run the full test suite (incl. `MySqlConcurrencyTest`) on **MySQL 8.x** in staging | Only MariaDB 10.4 exists on the development PC; the spec mandates MySQL 8 |
| B-2 | Deploy once to a **Linux + Nginx + PHP-FPM staging** server with HTTPS/WSS, Supervisor/systemd and cron, following [18-PRODUCTION-DEPLOYMENT.md](18-PRODUCTION-DEPLOYMENT.md) | No production-like deployment was performed |
| B-3 | Set production `.env` values (`APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, real `REVERB_ALLOWED_ORIGINS`, SMTP) | `.env.example` is local-oriented on purpose |
| B-4 | Perform one **backup + restore drill** (database **and** `storage/app/private`) | Recovery is documented but never executed |
| B-5 | Click through real-time notifications in **two real browsers** (toast, badge, fallback) | Browser UI was not automated; the WebSocket path was verified with a Node client instead |

"Controlled" means: a limited set of real users, an administrator watching logs and `failed_jobs`, and a rollback
plan (see [18](18-PRODUCTION-DEPLOYMENT.md) §rollback).

---

## 2. Environment used for verification

| Item | Value |
|---|---|
| PHP / Composer | 8.4.28 (`C:\php-8.4.28\php.exe`) / Composer on PHP 8.4.28 (verified `php -v`, `composer -V`) |
| Laravel | 13.44.1 |
| Database | XAMPP **MariaDB 10.4.27**, REPEATABLE-READ (verified `SELECT @@tx_isolation`) |
| Test DB | `resolvedesk_testing` (from `phpunit.xml`; no cached config, so the override really applies) |
| Audit DB | `resolvedesk_audit` — a **new, separate** database created, migrated and seeded for HTTP/Reverb checks |
| Dev DB | `resolvedesk` — **not modified**: row counts identical before and after the audit (10 users, 34 tickets, 88 notifications, 33 comments, 149 activities, 5 projects, 3 clients) |

---

## 3. Findings

Severity: **Critical** = exploitable data leak/loss now · **High** = authorization/integrity defect or go-live blocker ·
**Medium** = incorrect behaviour under realistic conditions · **Low** = hardening, UX or efficiency.

No Critical findings were identified.

### 3.1 Fixed in this audit

| ID | Severity | Finding | Fix | Regression test (failed before, passes after) |
|---|---|---|---|---|
| A-1 | **High** | **Authorization checked on a stale ticket copy.** Controllers run `Gate::authorize()` on the route-bound ticket, read *before* the row lock. Services re-checked only the status, not the actor. If a ticket was reassigned between those two moments, the *previous* agent could still resolve/start it or add (internal) comments. Forms send `version` (→ 409), but `version` is optional, so a hand-made request without it succeeded. | `TicketService::authorizeOnCurrentState()` re-runs `TicketPolicy` on the **locked** row in `startWork/resolve/reopen/close/addComment` (403). | `AuditRegressionTest::test_previous_agent_cannot_resolve_using_a_stale_ticket_copy`, `…cannot_comment_using_a_stale_ticket_copy` |
| A-2 | **Medium** | **Race: deactivating/demoting an agent vs assigning to that agent.** `UserController` counted the agent's tickets and then saved without a lock; `performAssignment()` read `is_active` without a lock. Both orders produced an **inactive agent owning an active ticket** (reproduced with two real processes). Same pattern allowed two admins to deactivate each other → 0 active admins. | `UserController::update()/toggleActive()` run in `DB::transaction(..., 3)` and lock the target `users` row first (`lockUser()`); the last-admin count uses `lockForUpdate()`; `performAssignment()` re-reads the agent with `lockForUpdate()`. Lock order documented. | `MySqlConcurrencyTest::test_deactivation_waits_for_a_running_assignment_and_is_refused`, `…assignment_waits_for_a_running_deactivation_and_is_refused` (second PHP process holds a real uncommitted transaction) |
| A-3 | **Medium** | **SLA reminder bumped `version`.** The scheduler runs every minute; each breach incremented `version`, so anyone with the (overdue!) ticket open got a false "changed by someone else" 409 on Start/Resolve/Edit. | `processSlaBreach()` saves `sla_notified_at` without a version bump (bookkeeping, not a user change; the edit UPDATE only writes changed columns, so no lost update). | `AuditRegressionTest::test_sla_reminder_does_not_invalidate_open_forms` |
| A-4 | **Medium** | **Edit form stuck after a conflict.** After a 409 the form re-rendered with `old('version')`, i.e. the stale version, so every further save failed again (endless conflict loop). | `tickets/edit.blade.php` always renders the current `$ticket->version`; the user's input is still kept and re-saving is a deliberate decision. | `AuditRegressionTest::test_edit_form_after_a_conflict_carries_the_current_version` |
| A-5 | **Medium** | **Dependency advisories.** `composer audit` reported 5 advisories (CVE-2026-133743/133746/133751/133754/133755) in `symfony/http-kernel`, `symfony/routing`, `symfony/mailer` 8.1.10–8.1.11 (URL host manipulation, STARTTLS MITM). | Patch-level update to **8.1.12** (`composer update symfony/http-kernel symfony/mailer symfony/routing --with-all-dependencies`, 6 packages, no major/minor change). | `composer audit` → "No security vulnerability advisories found"; full suite re-run green |

### 3.2 Remaining (not fixed — documented, with recommended action)

| ID | Severity | Finding | Recommended action |
|---|---|---|---|
| R-1 | **High** (blocker) | Not verified on MySQL 8.x (MariaDB 10.4 only). Locking/unique behaviour is the same InnoDB engine, but this is an assumption. | B-1: run `php artisan test` on MySQL 8 staging. |
| R-2 | **High** (blocker) | No Linux/Nginx/HTTPS/WSS deployment, no Supervisor/cron verification. | B-2. |
| R-3 | **High** (blocker) | Backup restore never executed. | B-4: restore DB + attachments to a scratch server, log in, download an attachment. |
| R-4 | Medium | Browser rendering of toasts/badge/fallback polling not automated (verified only the WebSocket protocol path with a Node pusher-js client, and the JS by code review). | B-5. |
| R-5 | Medium | Timestamps stored in `Asia/Dhaka`, not UTC (kept to avoid rewriting V1 data). Bangladesh has no DST, so values are unambiguous today. | Keep `APP_TIMEZONE` fixed; plan a one-time UTC migration if the app ever serves other time zones. |
| R-6 | Medium | Email delivery is at-least-once with cache-based de-duplication; a crash between DB commit and job dispatch loses the email (the in-app notification is still stored). | Accept for pilot; a transactional outbox only if email loss becomes a business problem. |
| R-7 | Low | `version` is optional on workflow POSTs (forms always send it). After A-1, a missing version can no longer bypass authorization, only skip the stale-intent check. | Optionally make `version` `required` and update the 47 test calls that omit it. |
| R-8 | Low | Login throttling is per email+IP only (no IP-wide limit against credential stuffing across many emails); lockout is shown as a validation message, not 429. | Add an IP-only limiter (e.g. 30/min) in `AuthController::login()`. |
| R-9 | Low | Redundant index `tickets_status_index` (leftmost prefix of `tickets_status_due_at_index`): extra write cost only. | Drop it in a small incremental migration when convenient. |
| R-10 | Low | Comments are not idempotent (double POST with JavaScript disabled creates two comments). Spec requires server idempotency only for ticket creation. | Accept; reuse the `request_token` pattern if needed. |
| R-11 | Low | Admin dashboard cache: a statistics build that started before a commit can be written after the invalidation → stale for up to 60 s (TTL). Overdue count is never cached. | Accept (documented in [15](15-CACHING-GUIDE.md)). |
| R-12 | Low | An admin may change the priority of a Resolved ticket; `due_at` is recalculated, which retroactively changes "resolved within SLA" statistics. | Decide the business rule; if unwanted, block priority edits when `resolved_at` is set. |
| R-13 | Low | Customer organisation change vs. a ticket creation already in flight uses the creator model loaded at request start (millisecond window; the change is blocked anyway once client tickets exist). | Accept. |
| R-14 | Low | Search uses `LIKE '%term%'` (full scan; `%`/`_` not escaped). Parameterised, so no injection. | Fine at current scale; add FULLTEXT later if needed. |
| R-15 | Low | Trusted proxies not configured (needed only behind a load balancer/CDN). | Configure `trustProxies()` if a proxy other than local Nginx is added. |
| R-16 | Low | Several open tabs each show the same toast (each tab has its own WebSocket). | Accept (documented). |

---

## 4. Requirement-by-requirement status

Status: **Implemented** · **Partial** · **Not verified in a production-like environment**.
"Verification method" names what was actually run; "Result" is the observed outcome.

| Requirement (spec §) | Status | Relevant source files | Verification method | Actual result | Remaining risk | Recommended action |
|---|---|---|---|---|---|---|
| Existing data preserved (§2, §41) | Implemented | `database/migrations/2026_11_26_*` (additive) | Row counts of dev DB before/after the audit | Identical | None | — |
| Projects/clients CRUD, search, pagination, deactivate-not-delete (§8–9) | Implemented | `ProjectController`, `ClientController`, `Project`, `Client`, `projects/*`, `clients/*` | `ProjectAndClientTest` (14), `PageRenderingTest` | Pass | Low | — |
| Many-to-many associations + removal guards (§10) | Implemented | `ProjectService` (project row `FOR UPDATE`), pivot migration 000004 | `ProjectAndClientTest`; code review of lock order | Pass | Low | — |
| Ticket scope (global/project × client/internal), server-side scope validation (§11–12) | Implemented | `StoreTicketRequest`, `TicketService::resolveScope()`, `TicketController::projectOptions()`, `public/js/ticket-form.js` | `TicketScopeTest` (17) incl. forged `client_id`, foreign project, inactive client/project | Pass | Low | — |
| Lifecycle + central transitions, closed read-only (§13) | Implemented | `Ticket::TRANSITIONS`, `TicketPolicy`, `TicketService` | `TicketWorkflowTest`, HTTP race S4 (close vs reopen) | Pass; exactly one of close/reopen won, other 409 | Low | — |
| Manual/auto assignment, eligibility, least workload (§14) | Implemented | `TicketAssignmentService`, `Ticket::canBeAssignedTo()` | `TicketWorkflowTest`, `ConsistencyTest`; HTTP race S3 (4 parallel auto-assigns, 4 server processes) | Pass; picks Fatima→Michael→Michael→Michael = serial least-workload result; 0 leftover cache locks | Lock is global (throughput) | — |
| Authorization on current state (§19.1, §27) | Implemented (**fixed A-1**) | `TicketService::authorizeOnCurrentState()`, `TicketPolicy` | `AuditRegressionTest` | Failed before fix, pass after | Low | R-7 optional |
| Customer isolation / IDOR (§6, §27) | Implemented | `TicketPolicy::view()`, `Ticket::scopeVisibleTo()`, `NotificationController` (own rows only) | `AuthorizationTest`, `TicketScopeTest`; HTTP check of foreign channel subscription | Pass | Low | — |
| Internal notes never reach customers (§15) | Implemented | `TicketComment::scopeVisibleTo()`, `TicketActivity::scopeVisibleTo()`, `TicketService::notifyCommentRecipients()`, `TicketPolicy::downloadAttachment()` | `InternalNotesAndAttachmentsTest`; live E2E: internal note posted by agent | Customer got **0** broadcasts, feed and ticket page did not contain the note text; mail log did not contain it; public reply (control) did reach the customer | Low | — |
| Secure attachments (§16) | Implemented | `AttachmentService`, `TicketAttachmentController`, `config/resolvedesk.php` | `InternalNotesAndAttachmentsTest` (type/size/count validation, private disk, 403 on foreign/internal file, orphan cleanup) | Pass | No antivirus (documented) | Add scanning before public exposure at scale |
| Activity audit trail, system actor (§17) | Implemented | `TicketActivity::record()` | Tests + HTTP S1/S2 activity counts | One activity per real change | Low | — |
| Transactions, after-commit side effects (§18) | Implemented | `TicketService`, `TicketNotifier::afterCommit()` | `QueueAndEmailTest` rollback test; Reverb-down HTTP test | No job on rollback; Reverb down → request 302 in 0.24 s, notification stored | R-6 | — |
| Pessimistic locking (§19.1) | Implemented | `lockTicket()`, `lockProjectAndTicket()`, `UserController::lockUser()` (**A-2**) | `MySqlConcurrencyTest` (5 tests, two processes/connections) | Pass; A-2 races reproduced before the fix | MySQL 8 unverified (R-1) | B-1 |
| Optimistic locking (§19.2) | Implemented (**fixed A-3, A-4**) | `updateTicket()`, `Ticket::ensureVersion()`, `TicketConflictException`, `tickets/edit.blade.php` | `ConsistencyTest`, `AuditRegressionTest`; HTTP race S2 | 4 parallel assigns with same version → 1×302, 3×409; 1 activity, 1 notification | Low | — |
| Atomic cache lock (§19.3) | Implemented | `withAssignmentLock()`, `config/resolvedesk.php` | HTTP race S3 with `CACHE_STORE=database` | Serial result; `cache_locks` empty afterwards | Lock can expire (10 s) — row lock remains the guarantee | — |
| Deadlock handling (§19.4) | Implemented | `DB::transaction(..., 3)` everywhere; documented lock order | Code review | — (no deadlock was forced) | Low | — |
| Idempotent ticket creation (§19.5) | Implemented | `createTicket()`, unique `(created_by, request_token)` | HTTP race S1: 8 parallel identical POSTs across 4 processes | **1** ticket, **1** `created` activity, **1** admin notification; all 8 responses 302 | Low | — |
| Notifications: bell, history, read/unread, mark read (§20) | Implemented | `NotificationController`, `TicketNotifier`, `notifications.js` | `NotificationTest` | Pass | Low | — |
| Real-time Reverb/Echo, private channels (§21) | Implemented; browser UI **not verified** | `UserNotificationCreated`, `routes/channels.php`, `resources/js/realtime.js`, `public/js/notifications.js` | Live E2E with real Reverb + 3 HTTP sessions (Node pusher-js 8.6.0) | 14/14 checks: own channel OK; foreign channel and guest auth refused (guest 403); event received **254 ms** after the admin's request; payload = `notification_id` only; id present in authorized feed | R-4 | B-5 |
| Polling fallback (§21) | Implemented | `notifications.js` | Reverb stopped, assignment over HTTP, feed fetched with agent session | Assignment OK; notification in feed; warning logged | Browser loop itself only code-reviewed | B-5 |
| Queued email, retries, timeout < retry_after (§22) | Implemented | `SendTicketEmail` (`tries=3`, `backoff=10`, `timeout=60`), `config/queue.php` (`retry_after=90`) | `QueueAndEmailTest`; `queue:work --stop-when-empty` on audit DB | 14 jobs processed, 0 failed | SMTP not tested (log mailer) | Test SMTP in staging |
| SLA command + dedupe (§23) | Implemented (**fixed A-3**) | `CheckSlaBreaches`, `processSlaBreach()`, `routes/console.php` | `SlaReportsAndHardeningTest`; **two `tickets:check-sla` processes in parallel** on audit DB | 6 overdue tickets → 3+3 split, 6 activities on 6 distinct tickets, 6 notifications (no duplicates) | Scheduler cron unverified on Linux | B-2 |
| Caching scope/invalidation (§24) | Implemented | `DashboardController`, `Category::activeForDropdown()`, `clearDashboardCache()` | `CacheTest` (incl. real database store) | Pass | R-11 | — |
| Dashboards, reports, CSV (§25) | Implemented | `DashboardController`, `ReportController`, `Ticket::slaSummary()` | `SlaReportsAndHardeningTest` (formula, CSV injection, authorization), `PageRenderingTest` | Pass | R-12 | — |
| Indexes / N+1 (§26) | Implemented | migrations 000005–000008; `Model::preventLazyLoading()` | `SHOW INDEX` review; `PageRenderingTest` renders every page with lazy loading forbidden | No N+1 on rendered pages; 1 redundant index (R-9) | Low | R-9 |
| Rate limiting (§28) | Implemented | `AppServiceProvider::configureRateLimiting()`, `AuthController` | `SlaReportsAndHardeningTest`, `AuthTest` | Pass | R-8 | — |
| Logging / request id / health (§29, §33) | Implemented | `AddRequestContext`, `/up`, `/health/ready` | HTTP: `/health/ready` on 4 processes → `{"status":"ok"}` | Pass | Daily logs only documented for prod | Set `LOG_STACK=daily` in prod |
| Production config, deployment, backup docs (§32–34) | Documented, **not executed** | [18](18-PRODUCTION-DEPLOYMENT.md), [19](19-BACKUP-AND-RECOVERY.md), [23](23-SECURITY-ERRORS-LOGGING-OPERATIONS.md) | Document review | — | R-2, R-3 | B-2, B-3, B-4 |
| Dependency advisories (§27) | Implemented (**fixed A-5**) | `composer.lock`, `package-lock.json` | `composer audit`, `npm audit` | 0 advisories / 0 vulnerabilities after the patch update | New advisories appear over time | Run both before every deployment |

---

## 5. What was executed (reproducible)

| Check | Command / method | Result |
|---|---|---|
| Baseline suite (before changes) | `php artisan test` | 164 passed (661 assertions) |
| New regression tests on **unfixed** code | `php artisan test tests/Feature/AuditRegressionTest.php tests/Feature/MySqlConcurrencyTest.php` | 4 + 2 failed (defects confirmed) |
| Full suite after fixes and Symfony patch update | `php artisan test` | **170 passed (682 assertions)**, ~22 s |
| Formatting | `vendor/bin/pint <changed files>` | passed |
| Dependency audit | `composer audit`, `npm audit` | clean / 0 vulnerabilities |
| HTTP concurrency (4 separate `php -S` processes on `resolvedesk_audit`, database cache/queue) | parallel `curl` with per-user cookie jars | S1–S4 as in §4 |
| SLA concurrency | two `php artisan tickets:check-sla` processes started together | no duplicate reminders |
| Real-time | `php artisan reverb:start` + Node pusher-js client, 3 logged-in sessions | 14/14 checks |
| Fallback | Reverb process stopped, then admin assignment over HTTP | operation succeeded, notification persisted |
| Queue | `php artisan queue:work --stop-when-empty` | 14 jobs done, 0 failed |

The audit scripts are not part of the repository; the two-process race tests that matter for regressions are in
`tests/Feature/MySqlConcurrencyTest.php` and run with the normal suite.

**Not executed:** MySQL 8, Linux deployment, Nginx/HTTPS/WSS proxying, Supervisor/systemd restarts, cron-driven
scheduler, SMTP delivery, browser UI automation, backup restore, load/performance testing, penetration testing.

---

## 6. Files changed in this audit

| File | Change |
|---|---|
| `app/Services/TicketService.php` | `authorizeOnCurrentState()` after locking (A-1); SLA reminder without version bump (A-3) |
| `app/Services/TicketAssignmentService.php` | Agent re-read with `lockForUpdate()` (A-2) |
| `app/Http/Controllers/UserController.php` | `update()`/`toggleActive()` in a transaction with `lockUser()`; locked last-admin count (A-2) |
| `resources/views/tickets/edit.blade.php` | Hidden `version` always current (A-4) |
| `composer.lock` | Symfony 8.1.10/11 → 8.1.12 (A-5) |
| `tests/Feature/AuditRegressionTest.php` | New: 4 regression tests |
| `tests/Feature/MySqlConcurrencyTest.php` | 2 new two-process race tests + `startBackgroundTransaction()` helper |
| Docs | This report, `CODEBASE-LEARNING-MAP.md`, README, DEVELOPMENT_PROGRESS, docs 02, 05, 06, 07, 08, 09, 11–16, 20, 21 |
| Bangla guides | `ResolveDesk-Project-Explanation-Bangla.docx`: new §29 (all five fixes in Bangla, with code, tests and interview lines) + corrections in §22, §23, §26, §28; `ResolveDesk-Process-Guide-UI-to-Database-Bangla.docx`: P7, P9, P10, P13, P14, P17, P22 and the security table updated |

> **বাংলায় সারসংক্ষেপ:** কোডের ব্যবসায়িক নিয়ম, authorization আর concurrency বাস্তব multi-process পরীক্ষায় ঠিকভাবে কাজ
> করেছে; পাওয়া পাঁচটি সমস্যা ঠিক করা হয়েছে এবং প্রতিটির জন্য test আছে। কিন্তু MySQL 8, Linux server, HTTPS এবং
> backup restore বাস্তবে পরীক্ষা করা হয়নি — তাই এখনই "সম্পূর্ণ production-ready" বলা যাবে না। Staging-এ এই পাঁচটি শর্ত
> (B-1…B-5) পূরণ করার পর সীমিত ব্যবহারকারী নিয়ে নিয়ন্ত্রিত deployment করা যায়।
