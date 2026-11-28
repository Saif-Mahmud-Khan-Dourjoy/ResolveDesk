# 12 — Quick Reference (V2 interview cheat sheet)

One-page revision sheet for ResolveDesk V2. Details: [System design](10-SYSTEM-DESIGN-GUIDE.md) ·
[Debugging](11-DEBUGGING-GUIDE.md) · [Business logic](06-BUSINESS-LOGIC-GUIDE.md) ·
[Concurrency](20-CONCURRENCY-AND-DATA-INTEGRITY.md) · [Real-time](21-REALTIME-REVERB-ECHO.md) ·
[V2 features](22-V2-BUSINESS-FEATURES.md) · [Security/ops](23-SECURITY-ERRORS-LOGGING-OPERATIONS.md) ·
[Deployment](18-PRODUCTION-DEPLOYMENT.md) · [Backup](19-BACKUP-AND-RECOVERY.md)

**Stack:** Laravel 13.44.1 · PHP 8.4.28 (`C:\php-8.4.28\php.exe`) · MariaDB 10.4 (MySQL 8 target, not tested) ·
Blade + Bootstrap 5.3 + Vanilla JS · Laravel Reverb 1.14 + Echo (Vite bundle `resources/js/realtime.js`) ·
database session/cache/queue · Scheduler · `MAIL_MAILER=log` · Tests: **170 passed / 682 assertions** (17 files).

**Demo logins** (local only, password `password`): `admin@resolvedesk.test` · `sarah.agent@resolvedesk.test`
(also david/fatima/michael) · `john.customer@example.com` (ACME; also emily, rahim = GLOBEX, olivia = standalone;
`tanvir` inactive).

---

## 1. Directory map (custom files)

```text
app/
  Console/Commands/CheckSlaBreaches.php   tickets:check-sla (scheduled every minute)
  Events/UserNotificationCreated.php      ShouldBroadcastNow, private users.{id}, payload = notification_id
  Exceptions/TicketConflictException.php  409 JSON / redirect back with input
  Http/Controllers/
    AuthController, DashboardController, NotificationController, CategoryController, UserController
    TicketController           CRUD (no destroy) + workflow + projectOptions (JSON)
    TicketCommentController    public reply / internal note (+ attachments)
    TicketAttachmentController authorized private download
    ProjectController          CRUD + updateClients / updateAgents
    ClientController           CRUD
    ReportController           report page + streamed CSV export
  Http/Middleware/  RoleMiddleware (role), ActiveUserMiddleware (active), AddRequestContext (web group)
  Http/Requests/StoreTicketRequest.php    create-ticket validation + authorize()
  Jobs/SendTicketEmail.php                tries 3, backoff 10, timeout 60, de-dup key
  Mail/TicketUpdateMail.php
  Models/  User, Ticket, Category, TicketComment, TicketActivity, Project, Client, TicketAttachment
  Notifications/TicketNotification.php    database channel
  Policies/TicketPolicy.php
  Providers/AppServiceProvider.php        Bootstrap pagination, preventLazyLoading, rate limiters
  Services/ TicketService, TicketAssignmentService, TicketNotifier, AttachmentService, ProjectService
config/resolvedesk.php                    attachments + assignment_lock settings
config/reverb.php, config/broadcasting.php
routes/web.php · routes/channels.php · routes/console.php
database/migrations/2026_11_26_00000{1..8}_*   8 additive V2 migrations
database/seeders/DatabaseSeeder.php, V2DemoDataSeeder.php
resources/js/realtime.js                  Echo -> browser events (built by Vite)
public/js/notifications.js                feed fetch, polling fallback, badge, toasts
resources/views/errors/403, 419, 429.blade.php
tests/Feature/                            16 files, 164 tests (DB: resolvedesk_testing)
```

## 2. Commands

Set PHP 8.4 first: `export PATH="/c/php-8.4.28:$PATH"` (Git Bash) / `$env:Path = "C:\php-8.4.28;$env:Path"` (PowerShell).

| Command | Purpose |
|---|---|
| `php artisan serve` | dev server :8000 |
| `php artisan queue:work` | email worker (restart after code changes: `queue:restart`) |
| `php artisan reverb:start [--debug]` | WebSocket server :8080 |
| `php artisan schedule:work` | local scheduler; server: cron `* * * * * php artisan schedule:run` |
| `php artisan schedule:list` | shows `tickets:check-sla` every minute |
| `php artisan tickets:check-sla` | run SLA breach check once |
| `php artisan migrate` / `migrate:status` | additive migrations (never `migrate:fresh` on real data) |
| `php artisan db:seed --class=V2DemoDataSeeder` | additive demo projects/clients |
| `php artisan test [--filter=ConsistencyTest]` | tests |
| `npm ci && npm run build` | build realtime bundle |
| `vendor/bin/pint --dirty` | format changed PHP files |
| `php artisan route:list --except-vendor` | 54 app routes |
| `php artisan queue:failed` / `queue:retry all` | failed jobs |
| `php artisan cache:clear` / `config:clear` / `optimize:clear` | caches |

## 3. Routes added in V2

Group A = `auth, active`. Group B = `auth, active, role:admin`.

| Name | Method + URI | Controller@method | Middleware |
|---|---|---|---|
| `health.ready` | GET `/health/ready` | closure (`SELECT 1` → ok / 503) | `throttle:60,1` |
| — | GET `/up` | framework liveness | — |
| — | POST `/broadcasting/auth` | `BroadcastController@authenticate` (framework) | web + session |
| `tickets.project-options` | GET `/tickets/project-options?client_id=` | `TicketController@projectOptions` (JSON) | A |
| `tickets.store` | POST `/tickets` | `TicketController@store` (`StoreTicketRequest`) | A + `throttle:ticket-create` |
| `tickets.attachments.download` | GET `/tickets/{ticket}/attachments/{attachment}` | `TicketAttachmentController@download` | A |
| `tickets.comments.store` | POST `/tickets/{ticket}/comments` | `TicketCommentController@store` | A + `throttle:comments` |
| `notifications.feed` | GET `/notifications/feed` | `NotificationController@feed` | A + `throttle:notifications` |
| `projects.*` | resource except destroy | `ProjectController` | B |
| `projects.clients.update` | PUT `/projects/{project}/clients` | `ProjectController@updateClients` | B |
| `projects.agents.update` | PUT `/projects/{project}/agents` | `ProjectController@updateAgents` | B |
| `clients.*` | resource except destroy | `ClientController` | B |
| `reports.index` | GET `/reports` | `ReportController@index` | B |
| `reports.export` | GET `/reports/export` | `ReportController@export` | B + `throttle:exports` |

Unchanged V1 routes: login/logout, dashboard, tickets index/create/show/edit/update, start/resolve/reopen/close,
assign/auto-assign (B), notifications index/read/read-all, users (B), categories (B). No destroy routes for
tickets, users, projects, clients.

## 4. Services (public methods)

| Service | Method | Summary |
|---|---|---|
| `TicketService` | `createTicket(array $data, User $creator, array $files = []): Ticket` | token replay check → store files → transaction(resolveScope, insert, number, activity, attachments, notify admins) → `wasRecentlyCreated` false on replay |
| | `updateTicket(Ticket, array, int $expectedVersion, User): bool` | conditional `UPDATE ... WHERE id=? AND version=? AND status<>'closed'`; 0 rows → 409 |
| | `startWork / resolve / reopen / close(..., ?int $expectedVersion = null)` | lock → `ensureVersion` → `ensureActionAllowed` → save + activity + notify |
| | `addComment(Ticket, string $message, string $visibility, User, array $files = []): TicketComment` | lock, not closed, internal = staff only |
| | `processSlaBreach(Ticket): bool` | lock, still overdue + `sla_notified_at IS NULL` → mark + notify once |
| | `clearDashboardCache()` | `Cache::forget('dashboard:admin:stats')` |
| `TicketAssignmentService` | `assignAgent(Ticket, User $agent, User $admin, ?int $expectedVersion = null): bool` | cache lock → transaction → project shared lock → ticket FOR UPDATE → eligibility; false = same agent |
| | `autoAssign(Ticket, User $admin, ?int $expectedVersion = null): ?User` | Open only; `findLeastBusyAgent($projectId)` inside the lock |
| | `findLeastBusyAgent(?int $projectId = null): ?User` | active agent (+ project member), fewest assigned+in_progress, tie lowest id |
| | `eligibleAgents(Ticket)` | dropdown list with `workload_count` |
| `TicketNotifier` | `notify(?User, Ticket, ?User $actor, string $eventType, string $title, string $message, bool $sendEmail)` | INSERT notification in transaction; after commit: broadcast + email job |
| | `notifyAdmins(...)`, `holdSideEffects()`, `releaseSideEffects()` | admins loop; delay side effects until the assignment lock is released |
| `AttachmentService` | `storeFiles()`, `createRecords()`, `deleteFiles()`, `validationRules()` | files before transaction, rows inside, cleanup on failure |
| `ProjectService` | `syncAgents(Project, array)`, `syncClients(Project, array)` | `FOR UPDATE` on project row, removal guards, clear dashboard cache |

## 5. Rate limits (`AppServiceProvider::configureRateLimiting()`)

| Limiter | Limit | Key | Route |
|---|---|---|---|
| `ticket-create` | 10/min | user id or IP | `POST /tickets` |
| `comments` | 30/min | user id or IP | `POST /tickets/{ticket}/comments` |
| `notifications` | 120/min | user id or IP | `GET /notifications/feed` |
| `exports` | 5/min | user id or IP | `GET /reports/export` |
| `throttle:60,1` | 60/min | IP | `/health/ready` |
| login (manual) | 5 failures / 60 s | email + IP | `AuthController::login()` |

Exceeded → **429** (`resources/views/errors/429.blade.php` or JSON).

## 6. Config keys (`config/resolvedesk.php`)

| Key | Default | Meaning |
|---|---|---|
| `resolvedesk.attachments.disk` | `env('ATTACHMENT_DISK', 'local')` | private disk (`storage/app/private`) |
| `resolvedesk.attachments.max_file_kb` | `5120` | 5 MB per file |
| `resolvedesk.attachments.max_files` | `5` | per request |
| `resolvedesk.attachments.allowed_extensions` | pdf, png, jpg, jpeg, txt, docx, xlsx | checked by `extensions:` and `mimes:` |
| `resolvedesk.assignment_lock.key` | `ticket-assignment` | `Cache::lock` name |
| `resolvedesk.assignment_lock.seconds` | `10` | lock auto-expiry |
| `resolvedesk.assignment_lock.wait_seconds` | `5` | `block()` wait before "try again" |

Other numbers in code: `Ticket::SLA_HOURS` (48/24/8/2), `DashboardController::ADMIN_STATS_TTL_SECONDS = 60`,
`Category::ACTIVE_CACHE_TTL_SECONDS = 3600`, `SendTicketEmail::$tries = 3`, `$backoff = 10`, `$timeout = 60`,
`retry_after = 90` (`config/queue.php`), `POLL_INTERVAL_MS = 5000` (`public/js/notifications.js`),
broadcaster `connect_timeout 2` / `timeout 3` (`config/broadcasting.php`).

## 7. Tables and new columns

| Table | V2 columns / notes |
|---|---|
| `projects`, `clients` | `code` unique, `is_active` |
| `client_project`, `project_user` | unique pair, `restrictOnDelete` |
| `users` | `client_id` nullable |
| `tickets` | `project_id`, `client_id`, `version` (default 1), `request_token` (unique with `created_by`), `sla_notified_at`; indexes `(project_id,status)`, `(client_id,status)`, `(status,due_at)` |
| `ticket_comments` | `visibility` public/internal |
| `ticket_activities` | `is_internal`, `user_id` nullable (system) |
| `ticket_attachments` | `ticket_id`, `ticket_comment_id`, `uploaded_by`, `original_name`, `storage_path`, `mime_type`, `size` |
| `notifications` | index `(notifiable_type, notifiable_id, created_at)` |
| `cache_locks` | used by `Cache::lock()` and `withoutOverlapping()` |

## 8. Status codes you will see

| Code | In ResolveDesk |
|---|---|
| 302 + error | validation / business rule (`ValidationException`), lock timeout ("Another assignment is being processed") |
| 403 | policy denied, `role:admin`, `/broadcasting/auth` for another user's channel, internal attachment for customer |
| 404 | missing model, attachment file missing on disk |
| 409 | `TicketConflictException` for JSON requests (stale `version`, reused `request_token` with different content); HTML forms get redirect back + flash error |
| 419 | CSRF / session expired |
| 422 | JSON validation |
| 429 | rate limiter |
| 503 | `/health/ready` when the database is down |

## 9. Interview cheat sheet (V2 spec §38 topics)

Each line: **concise answer** → *project example*.

| Topic | Answer → example |
|---|---|
| Core PHP | Types, classes, closures, exceptions, enums-as-constants → `Ticket::STATUSES`, typed `?int $expectedVersion`, closures passed to `DB::transaction()` |
| Laravel MVC | Route → controller → model/service → Blade view → `TicketController::show()` renders `tickets/show.blade.php` |
| Routing | Named routes, resource routes, route order matters → `/tickets/project-options` registered **before** `Route::resource('tickets')` |
| Middleware | Code around every request → `AddRequestContext` (request id), `active`, `role:admin`, `throttle:ticket-create` |
| Controllers | Thin: authorize, validate, call service, respond → `TicketController::assign()` |
| Validation | `$request->validate()` or Form Request; business rules in service → `StoreTicketRequest` (format) + `TicketService::resolveScope()` (scope) |
| Eloquent | ORM, scopes, casts, conditional update → `Ticket::scopeVisibleTo()`, `Ticket::whereKey()->where('version', $v)->update()` |
| Relationships | belongsTo / hasMany / belongsToMany → `Project::clients()`, `Project::agents()`, `User::projects()` via `client_project` / `project_user` |
| Authentication | Session guard, hashed passwords, regenerate session → `AuthController::login()` |
| Authorization | RBAC + resource policy → `role:admin`, `TicketPolicy::downloadAttachment()`, `routes/channels.php` |
| Blade | Escaped output, `@can`, components → `@can('addInternalNote', $ticket)` shows the internal checkbox |
| Service container | Builds objects and their dependencies → `TicketService` gets `TicketNotifier` + `AttachmentService` by constructor injection |
| Dependency injection | Ask for what you need in constructor/method → `CheckSlaBreaches::handle(TicketService $ticketService)` |
| Transactions | All-or-nothing; `DB::transaction(fn, 3)` retries deadlocks → every service mutation |
| Concurrency | Pessimistic (`lockForUpdate`), optimistic (`version`), atomic (`Cache::lock`), idempotency (`request_token`) → see [20](20-CONCURRENCY-AND-DATA-INTEGRITY.md) |
| Cache | Cache-aside + invalidation + locks → `Cache::remember('dashboard:admin:stats', 60, ...)`, `Cache::forget()` in `ProjectController::clearCaches()` |
| Queue | Producer/consumer, retries, timeout < retry_after → `SendTicketEmail` dispatched in `DB::afterCommit()` |
| Events | Something happened → listeners/broadcast → `UserNotificationCreated` (`ShouldBroadcastNow`) |
| Notifications | Laravel `Notifiable`, database channel → `TicketNotification`, history page, unread badge |
| WebSockets | Persistent connection, server push; private channel auth → Reverb + Echo, `users.{id}`, payload only `notification_id` |
| Scheduler | One cron calls `schedule:run`; tasks defined in code → `Schedule::command('tickets:check-sla')->everyMinute()->withoutOverlapping()` |
| Testing | Feature tests with `RefreshDatabase`, fakes → `Queue::fake()`, `Event::fake()`, two-connection `MySqlConcurrencyTest` |
| Production deployment | `APP_DEBUG=false`, config/route/view cache, Supervisor for worker + Reverb, cron for scheduler, HTTPS proxy for WSS → [18](18-PRODUCTION-DEPLOYMENT.md) (documented, not executed) |

> **বাংলায়:** ইন্টারভিউতে প্রতিটি প্রশ্নে আগে এক লাইনের সংজ্ঞা দিন, তারপর বলুন "আমার প্রজেক্টে এটা অমুক ফাইলে
> এভাবে আছে"। বাস্তব উদাহরণ দেখালে বোঝা যায় আপনি কোডটা সত্যিই বোঝেন।

## 10. Notification matrix

| Event | In-app to | Email |
|---|---|---|
| created | active admins (not the creator) | yes |
| assigned / reassigned | new agent | yes |
| unassigned | previous agent | no |
| resolved | creator | yes |
| reopened | assigned agent | yes |
| closed | assigned agent | no |
| comment | creator + agent (not author) | only customer reply → agent |
| internal_note | agent + staff creator | no |
| sla_breached | active assigned agent, else admins | yes |

Real-time: after commit `UserNotificationCreated` → Reverb → Echo `.notification.created` → browser event
`resolvedesk:realtime-notification` → `notifications.js` fetches `/notifications/feed`.

## 11. Cache keys

| Key | TTL | Cleared by |
|---|---|---|
| `dashboard:admin:stats` | 60 s | ticket services, `ProjectService`, `ProjectController`, `ClientController`, `UserController`, `CategoryController` |
| `ticket:active-categories` | 3600 s | `Category::clearActiveCache()` |
| `ticket-assignment` (lock) | 10 s | released by `block()` |
| `ticket-email-sent:{notification uuid}` | 2 days | expiry |
| scheduler `withoutOverlapping` lock | until task ends | framework |

## 12. "To change X, edit Y"

| To change… | Edit |
|---|---|
| SLA hours | `Ticket::SLA_HOURS` |
| Attachment size/types/count | `config/resolvedesk.php` → `attachments` |
| Assignment lock timing | `config/resolvedesk.php` → `assignment_lock` |
| Auto-assign algorithm / eligibility | `TicketAssignmentService::findLeastBusyAgent()`, `Ticket::canBeAssignedTo()` |
| Who is notified | `TicketNotifier::notify()` calls in services, `TicketService::notifyCommentRecipients()` |
| Real-time event name/payload | `UserNotificationCreated::broadcastAs()` / `broadcastWith()` + `resources/js/realtime.js` |
| Channel rule | `routes/channels.php` |
| SLA reminder frequency | `routes/console.php` |
| Rate limits | `AppServiceProvider::configureRateLimiting()` |
| Ticket scope rules | `TicketService::resolveScope()` + `TicketController::selectableProjects()` |
| Report columns | `ReportController::export()` + `resources/views/reports/index.blade.php` |
| Create-ticket validation | `app/Http/Requests/StoreTicketRequest.php` |
| Polling interval | `POLL_INTERVAL_MS` in `public/js/notifications.js` |

## 13. Debugging in 10 steps

1. Status code (section 8) and the flash message.
2. `X-Request-Id` response header → `grep <id> storage/logs/laravel.log`.
3. `php artisan route:list --except-vendor -v` for middleware.
4. 409 → someone changed the ticket; reload. 429 → wait a minute.
5. No toast → Reverb running? F12 Network → WS frames; `/broadcasting/auth` 200?
6. No email → `SELECT * FROM jobs`, `php artisan queue:failed`.
7. No SLA reminder → scheduler running? `php artisan tickets:check-sla` manually; `sla_notified_at` already set?
8. Attachment 403/404 → policy (`downloadAttachment`) vs. missing file on disk (log "Attachment file missing").
9. Stale numbers → `Cache::forget('dashboard:admin:stats')` or `php artisan cache:clear`.
10. `php artisan test` after every fix.
