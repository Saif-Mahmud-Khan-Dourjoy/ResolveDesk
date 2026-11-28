# ResolveDesk — Codebase Learning Map (exact study order)

Updated: 2026-11-26 (after the production-readiness audit, see [PRODUCTION-READINESS-REPORT.md](PRODUCTION-READINESS-REPORT.md)).

Study the files **in this order**. Each stage only uses ideas from earlier stages: first the URLs, then the data,
then who may do what, then simple CRUD, then the ticket engine, and finally the background parts (notifications,
queue, scheduler, WebSockets). For every important file you get:

- **Purpose** — why the file exists.
- **Depends on** — what you must understand first.
- **UI actions** — which button/page reaches it.
- **Business logic** — the rules to be able to explain.
- **Likely interview modifications** — what an interviewer may ask you to change, and where.

Older, more detailed method-level walkthrough: [16-CODEBASE-LEARNING-MAP.md](16-CODEBASE-LEARNING-MAP.md).
UI → route → code table: [07-UI-TO-BACKEND-MAPPING.md](07-UI-TO-BACKEND-MAPPING.md).

> **বাংলায় কীভাবে পড়বেন:** প্রতিটি ফাইল খুলে প্রথমে উপরের comment পড়ুন, তারপর ব্রাউজারে সংশ্লিষ্ট বাটনে ক্লিক করে
> `storage/logs/laravel.log` আর ডাটাবেসে কী বদলাল দেখুন। "পড়া → চালানো → বদলানো" — এই তিন ধাপে শিখলে ইন্টারভিউতে
> নিজে থেকে পরিবর্তন করতে পারবেন।

---

## Stage 0 — The map of the application (≈15 min)

### 1. `routes/web.php`
- **Purpose:** every URL, its HTTP method, controller method, route name and middleware. The table of contents of the app.
- **Depends on:** nothing.
- **UI actions:** all of them.
- **Business logic:** route groups decide broad access: `guest` (login), `auth + active` (everyone logged in),
  `role:admin` (assignment, users, categories, projects, clients, reports). Named throttles: `ticket-create`,
  `comments`, `notifications`, `exports`. `/health/ready` checks the database. `tickets/project-options` is declared
  before the resource so it is not read as a `{ticket}` id.
- **Likely modifications:** add a route (e.g. `POST /tickets/{ticket}/escalate`), move an action into the admin
  group, add `->middleware('throttle:...')`. Run `php artisan route:list` afterwards.

### 2. `bootstrap/app.php`
- **Purpose:** registers routing files (web, console, channels, `/up`) and middleware aliases.
- **Depends on:** 1.
- **Business logic:** `role` and `active` aliases; `AddRequestContext` appended to the `web` group; guests go to `/login`.
- **Likely modifications:** register a new middleware alias; add exception rendering in `withExceptions()`.

### 3. `config/resolvedesk.php`
- **Purpose:** business settings you can change live: attachment disk/size/count/types, assignment lock key/expiry/wait.
- **Likely modifications:** "allow `.csv` uploads" (add to `allowed_extensions`), "max 10 MB" (`max_file_kb`).

---

## Stage 1 — The data (≈40 min)

### 4. Migrations, in this order
`0001_01_01_000000_create_users_table` → `2026_11_25_000001…000004` (categories, tickets, comments, activities) →
`2026_11_25_140742_create_notifications_table` → `2026_11_26_000001…000008` (V2: projects, clients,
`users.client_id`, pivots `client_project`/`project_user`, ticket V2 columns, comment/activity visibility,
attachments, notification feed index).
- **Purpose:** the schema. Read each `up()` and `down()`.
- **Business logic:** `restrictOnDelete()` everywhere history matters (users, projects, clients are deactivated, never
  deleted); `ticket_number` UNIQUE; `UNIQUE(created_by, request_token)` = idempotency; `version` default 1;
  `due_at`/`resolved_at` are DATETIME (MariaDB TIMESTAMP auto-update bug); composite indexes `(project_id,status)`,
  `(client_id,status)`, `(assigned_to,status)`, `(status,due_at)`; pivots have UNIQUE pairs + reverse index.
- **Likely modifications:** "add `phone` to users" → new migration `add_phone_to_users_table`, never edit an old one;
  "add an index" → explain which query uses it (`EXPLAIN`). Known redundant index: `tickets_status_index` (report R-9).

### 5. `app/Models/User.php`
- **Purpose:** login account for the three roles; `Notifiable`.
- **Business logic:** `ROLES` constants; `client_id` is NOT fillable (set only after business checks);
  `isStaff()` = admin or agent (may see internal notes); `projects()` = project membership (assignment eligibility
  only, **not** visibility).
- **Likely modifications:** add a role (constant + `ROLES` + policy rules); add an `is_available` flag for agents.

### 6. `app/Models/Category.php`
- **Purpose:** ticket category + cached dropdown (`activeForDropdown()`).
- **Business logic:** caches a **plain array** (Laravel 13 refuses to unserialize objects from cache), `hydrate()`
  rebuilds models; the cache is for display only — saving validates against the table.
- **Likely modifications:** change TTL; add `description` to the cached columns.

### 7. `app/Models/Project.php`, 8. `app/Models/Client.php`
- **Purpose:** V2 products and client organisations; `belongsToMany` both ways; `scopeActive()`; `displayName()`.
- **Likely modifications:** add a `reference` field; add `withCount('tickets')` somewhere.

### 9. `app/Models/Ticket.php` ⭐
- **Purpose:** the heart of the domain. Read it slowly.
- **Business logic:**
  - `STATUSES`, `TRANSITIONS` (start/resolve/close/reopen), `ACTIVE_STATUSES`, `WORKLOAD_STATUSES`,
    `OPEN_OR_REOPENABLE_STATUSES`, `PRIORITIES`, `SLA_HOURS` (critical 2, high 8, medium 24, low 48).
  - `$fillable` only subject/description/category/priority — status, owner, scope, version are never mass-assigned.
  - `calculateDueAt()`, `makeTicketNumber()` (from the DB id, never MAX+1), `canPerform()`, `isOverdue()` (live),
    `ensureVersion()` (optimistic lock check), `canBeAssignedTo()` (active agent + project membership, read from DB),
    `slaSummary()` (one aggregate query; null compliance when nothing resolved), `scopeVisibleTo()`
    (admin all; others created OR assigned).
- **Likely modifications:** "add priority Urgent (1 h)" → `PRIORITY_*`, `PRIORITIES`, `SLA_HOURS`, badge CSS;
  "add status On Hold" → constants + `TRANSITIONS` + policy + service method + button; "agents see all tickets of
  their projects" → change `scopeVisibleTo()` **and** `TicketPolicy::view()` together.

### 10. `app/Models/TicketComment.php`, 11. `TicketActivity.php`, 12. `TicketAttachment.php`
- **Business logic:** `visibility` public/internal + `scopeVisibleTo()` (customers get `WHERE visibility='public'`);
  `TicketActivity::record()` is the only writer, `user_id NULL` = system, `is_internal` hides entries from customers;
  attachments have no fillable fields and inherit the visibility of their comment.
- **Likely modifications:** add an activity type (label in `ACTION_LABELS`); show attachment uploader.

---

## Stage 2 — Who may do what (≈40 min)

### 13. `app/Http/Controllers/AuthController.php`
- **UI:** login page, Logout button.
- **Business logic:** 5 failed attempts per email+IP → 60 s lockout; `Auth::attempt()`; inactive → logout again;
  `session()->regenerate()` (session fixation); logout invalidates session + CSRF token.
- **Likely modifications:** add an IP-wide limiter (report R-8); "remember me" duration.

### 14. `app/Http/Middleware/ActiveUserMiddleware.php`, 15. `RoleMiddleware.php`, 16. `AddRequestContext.php`
- **Business logic:** a deactivated user is logged out on the next request (401 JSON for fetch); `role:admin,agent`
  syntax; every log line gets `request_id` + `user_id`, returned in `X-Request-Id`.
- **Likely modifications:** allow agents to see reports → `role:admin,agent` + filter by `visibleTo()`.

### 17. `app/Policies/TicketPolicy.php` ⭐
- **Purpose:** resource-level authorization: view, create, update, assign, start, resolve, close, reopen, comment,
  addInternalNote, downloadAttachment.
- **UI:** `@can(...)` hides buttons in `tickets/show.blade.php`; controllers call `Gate::authorize()`.
- **Business logic:** creator may edit only while Open + unassigned; assigned agent or admin starts/resolves; creator
  or admin closes/reopens; internal notes staff-only; internal-note files staff-only; customers of an inactive client
  cannot create. **Audit fix A-1:** `TicketService` re-runs these rules on the locked row.
- **Likely modifications:** "customers cannot reopen after 7 days" → add a date check in `reopen()`; write a test.

### 18. `app/Providers/AppServiceProvider.php`
- **Business logic:** Bootstrap 5 pagination; `Model::preventLazyLoading()` outside production (N+1 becomes an
  exception); named rate limiters per user id (guests per IP).
- **Likely modifications:** change a limit; add a limiter for downloads.

---

## Stage 3 — The page shell and shared JavaScript (≈20 min)

### 19. `resources/views/layouts/app.blade.php`, `partials/sidebar.blade.php`, `partials/navbar.blade.php`, `partials/flash.blade.php`
- **Business logic:** `<meta name="csrf-token">`, `<meta name="user-id">`; the Vite bundle (`realtime.js`) is loaded
  only if `public/build/manifest.json` exists (otherwise the app runs on polling); sidebar items by role.
- **Likely modifications:** add a menu item for a role.

### 20. `public/js/app.js`
- **UI:** every `<form data-confirm>` and `<form data-disable-on-submit>`.
- **Business logic:** confirmation dialog; disables submit buttons (UI only — the server-side protection is the
  request token / version).

---

## Stage 4 — Simple CRUD (≈40 min)

### 21. `app/Http/Controllers/CategoryController.php`
- **UI:** Categories page (admin). **Logic:** delete only unused categories; clears category + dashboard cache.
- **Modifications:** add `description`; prevent deactivating a category with open tickets.

### 22. `app/Http/Controllers/UserController.php` ⭐
- **UI:** Users list/create/edit, Activate/Deactivate button.
- **Business logic:** customers only may have a client; cannot deactivate/demote yourself; never the last active
  admin; an agent with Assigned/In Progress/Resolved tickets cannot be deactivated or demoted; a customer's client
  cannot change once they created client tickets. **Audit fix A-2:** `update()`/`toggleActive()` run in a
  transaction and `lockUser()` (`SELECT … FOR UPDATE`) first, so a parallel assignment cannot slip between check and save.
- **Likely modifications:** "auto-unassign tickets when deactivating an agent" → inside the same transaction, lock the
  agent's tickets and set them back to Open (and record activities); "add phone number".

### 23. `app/Http/Controllers/ClientController.php`, 24. `ProjectController.php`, 25. `app/Services/ProjectService.php`
- **UI:** Clients/Projects pages; checkbox lists "Clients using this project" and "Support agents" on the project page.
- **Business logic:** codes unique + upper-cased; deactivate instead of delete; `ProjectService::syncAgents/syncClients`
  lock the project row `FOR UPDATE`, allow only active clients/agents to be added, refuse removals while open or
  reopenable tickets depend on them.
- **Likely modifications:** add client-wise statistics; allow inactive agents to be removed freely.

---

## Stage 5 — Tickets: reading and forms (≈45 min)

### 26. `app/Http/Controllers/TicketController.php` ⭐ (index, create, store, projectOptions, show, edit, update; workflow actions)
- **UI:** Tickets list + filters, New Ticket, ticket page buttons (Assign, Auto Assign, Start, Resolve, Close, Reopen),
  Edit Ticket.
- **Business logic:** filters validated; `visibleTo()` applied **before** filters; `applyFilters()` shared with reports;
  non-admin project filter only lists projects of visible tickets; `show()` loads comments/activities through
  `visibleTo($user)`; new categories must be active; every workflow action = policy → validation → service.
- **Likely modifications:** add a filter (validate + `applyFilters()` + Blade select); change `PER_PAGE`; add a sort
  column (whitelist it).

### 27. `app/Http/Requests/StoreTicketRequest.php`
- **Business logic:** format validation only (active category, priority, ids exist, `request_token` UUID,
  attachments); scope **rules** live in `TicketService::resolveScope()` because they need a lock.

### 28. Views `resources/views/tickets/`: `create`, `_form`, `_scope-fields`, `_attachment-input`, `index`, `show`, `edit`, `_attachments`
- **Business logic:** hidden `request_token` (create) and `version` (every mutation form; edit always uses the
  **current** version — audit fix A-4); `{{ }}` escaping; internal notes styled and badged; SLA panel.
- **JS:** `public/js/ticket-form.js` (admin client → project dropdown via `GET /tickets/project-options`).

---

## Stage 6 — Ticket engine: writing (≈60 min)

### 29. `app/Services/TicketService.php` ⭐⭐
- **Purpose:** every coordinated ticket rule: create, edit, start, resolve, reopen, close, comments/internal notes, SLA breach.
- **Depends on:** 9, 17, 30, 34.
- **Business logic, method by method:**
  - `createTicket()` — idempotency (look up token → replay; UNIQUE index catches the parallel duplicate →
    replay or 409 if content differs); files stored first and deleted again on any failure; one transaction for
    ticket + number + activity + attachments + admin notifications.
  - `resolveScope()` — locks the project row (shared) first; customer → own client, standalone → global only;
    agent → internal, supported projects only; admin → active client, client must use project.
  - `updateTicket()` — optimistic lock: one conditional `UPDATE … WHERE id=? AND version=? AND status<>'closed'`;
    priority change recalculates `due_at` from `created_at` and resets `sla_notified_at`.
  - `startWork/resolve/reopen/close()` — transaction → `lockTicket()` → `ensureVersion()` → `ensureActionAllowed()` →
    `authorizeOnCurrentState()` (A-1) → change + `saveNewVersion()` → activity → notification.
    Reopen re-checks agent eligibility and keeps the original SLA deadline.
  - `addComment()` — locks the ticket, refuses closed tickets, re-authorizes, internal notes notify staff only, email
    only for a customer's public reply to the agent.
  - `processSlaBreach()` — locked check-and-mark of `sla_notified_at`; system actor; **no version bump** (A-3).
- **Likely modifications:** add a "Put on hold" transition; notify the customer on Start; change the reopen SLA rule;
  "debug a failing ticket creation" (validation errors in session, `request_token`, scope messages, logs).

### 30. `app/Services/AttachmentService.php`, 31. `app/Http/Controllers/TicketAttachmentController.php`, 32. `TicketCommentController.php`
- **UI:** file inputs on New Ticket and on the reply form; attachment links.
- **Business logic:** generated storage names on the private disk; extension **and** detected MIME validated;
  downloads only through `downloadAttachment` policy with `Content-Disposition: attachment` + `nosniff`;
  `visibility=internal` requires `addInternalNote`.
- **Likely modifications:** allow `.csv`; restrict size per role; add delete-attachment (policy + file removal after commit).

### 33. `app/Exceptions/TicketConflictException.php`
- **Business logic:** stale version / reused token → JSON 409, or redirect back with input and an error message.

---

## Stage 7 — Assignment and concurrency (≈45 min)

### 34. `app/Services/TicketAssignmentService.php` ⭐⭐
- **UI:** Assign/Reassign select and Auto Assign button (admin, ticket page).
- **Business logic:** lock order **cache lock `ticket-assignment` → project row (shared) → ticket row (FOR UPDATE) →
  agent users row (FOR UPDATE, A-2)**; `findLeastBusyAgent()` = `whereHas('projects')` + `withCount` of
  Assigned/In Progress, ties by id; same agent again = no-op; Open → Assigned; reassignment keeps the status and
  notifies the previous agent with ticket number only; side effects held until the cache lock is released.
- **Likely modifications:** add `is_available` and filter on it in both `findLeastBusyAgent()` and
  `canBeAssignedTo()`; per-project locks; count only Assigned tickets.
- **Read next:** [20-CONCURRENCY-AND-DATA-INTEGRITY.md](20-CONCURRENCY-AND-DATA-INTEGRITY.md) and run
  `php artisan test --filter=MySqlConcurrencyTest` (two real processes).

---

## Stage 8 — Notifications and real time (≈45 min)

### 35. `app/Services/TicketNotifier.php` ⭐
- **Business logic:** skips inactive recipients and the actor; inserts the DB notification **inside** the transaction;
  broadcast and email only **after commit** (`DB::afterCommit()`), each in try/catch; `holdSideEffects()` during the
  assignment lock.
### 36. `app/Notifications/TicketNotification.php` — the `data` JSON (ticket_id, number, title, message, event_type).
### 37. `app/Http/Controllers/NotificationController.php`
- **UI:** bell dropdown, Notifications page, mark read / mark all read.
- **Business logic:** always starts from `$request->user()->notifications()` (no IDOR); feed `since` with a 10 s overlap.
### 38. `app/Events/UserNotificationCreated.php` + `routes/channels.php`
- **Business logic:** `ShouldBroadcastNow` on `private users.{id}`; payload only `notification_id`; channel callback
  allows only the same, active user.
### 39. `resources/js/realtime.js` (Vite) and `public/js/notifications.js`
- **Business logic:** Echo connects/subscribes and fires browser events; notifications.js polls every 5 s only while
  the WebSocket is not healthy, fetches once after (re)connecting, de-duplicates toasts by id, renders with
  `textContent` (XSS-safe).
- **Likely modifications:** new trigger (call `notify()` in the right service method); change poll interval; add an
  unread filter.
- **Read next:** [13](13-NOTIFICATION-SYSTEM-GUIDE.md), [21](21-REALTIME-REVERB-ECHO.md).

---

## Stage 9 — Queue and email (≈20 min)

### 40. `app/Jobs/SendTicketEmail.php`, 41. `app/Mail/TicketUpdateMail.php`, 42. `resources/views/emails/ticket-update.blade.php`
- **Business logic:** plain-value payload; `tries=3`, `backoff=10`, `timeout=60` < `retry_after=90`
  (`config/queue.php`); cache key per notification id skips a re-sent email; `failed()` logs without the address.
- **Likely modifications:** change retries/backoff; add a field to the template; send resolution emails to the agent too.
- **Commands:** `php artisan queue:work`, `queue:failed`, `queue:retry all`.

---

## Stage 10 — Scheduler (≈15 min)

### 43. `app/Console/Commands/CheckSlaBreaches.php` + `routes/console.php`
- **Business logic:** `overdue()->whereNull('sla_notified_at')->chunkById(100)`; each ticket processed by
  `processSlaBreach()`; scheduled `everyMinute()->withoutOverlapping()`.
- **Likely modifications:** add a "due in 1 hour" reminder (new nullable column + same lock-and-mark pattern).
- **Commands:** `php artisan tickets:check-sla`, `php artisan schedule:work`.

---

## Stage 11 — Dashboards and reports (≈30 min)

### 44. `app/Http/Controllers/DashboardController.php`
- **Business logic:** admin aggregates cached 60 s under `dashboard:admin:stats` (admin-only data, so one key is
  safe); overdue, workload and recent activity always live; agent/customer dashboards never cached and use their own ids.
### 45. `app/Http/Controllers/ReportController.php` + `resources/views/reports/index.blade.php`
- **Business logic:** same filters as the list; CSV streamed with `chunkById(500)`; formula-injection prefix `'`;
  SLA compliance = resolved within SLA ÷ resolved.
- **Likely modifications:** add a CSV column; add client-wise chart; add a date-range preset.

---

## Stage 12 — Tests (≈45 min) — read them as executable documentation

Order: `AuthTest` → `AuthorizationTest` → `TicketManagementTest` → `TicketWorkflowTest` → `TicketScopeTest` →
`ProjectAndClientTest` → `ConsistencyTest` → `MySqlConcurrencyTest` → `InternalNotesAndAttachmentsTest` →
`NotificationTest` → `RealtimeAndDeliveryTest` → `QueueAndEmailTest` → `CacheTest` → `SlaReportsAndHardeningTest` →
`UserAndCategoryManagementTest` → `PageRenderingTest` → `AuditRegressionTest`.

- `MySqlConcurrencyTest::startBackgroundTransaction()` starts a **second PHP process** that holds a real,
  uncommitted transaction while the test sends the HTTP request — the way to prove a race fix.
- **Likely modification:** "add a feature test" → copy a test from the closest file, `actingAs($user)`, `post(route(...))`,
  `assertSessionHasErrors` / `assertForbidden`, then assert the database.

---

## Stage 13 — Configuration you must be able to explain (≈15 min)

`config/queue.php` (retry_after), `config/session.php` (secure/http_only/same_site), `config/reverb.php`
(`REVERB_ALLOWED_ORIGINS`), `config/broadcasting.php` (2 s/3 s timeouts), `config/logging.php` (`LOG_STACK=daily`
in production), `.env.example`, `phpunit.xml` (isolated `resolvedesk_testing`).
Then read [18-PRODUCTION-DEPLOYMENT.md](18-PRODUCTION-DEPLOYMENT.md) and [PRODUCTION-READINESS-REPORT.md](PRODUCTION-READINESS-REPORT.md).

---

## Three-hour plan

| Time | Stages | Hands-on check |
|---|---|---|
| 0:00–0:30 | 0–1 | `php artisan route:list`; open `tickets` table in phpMyAdmin |
| 0:30–1:00 | 2–4 | Deactivate an agent who has tickets → read the error, find the rule in `UserController` |
| 1:00–1:50 | 5–6 | Create a ticket, double-submit with the same token (`ConsistencyTest`), resolve it |
| 1:50–2:20 | 7 | Run `MySqlConcurrencyTest`; explain the lock order aloud |
| 2:20–2:45 | 8–10 | Two browsers (admin + agent), assign → toast; stop Reverb → polling still works |
| 2:45–3:00 | 11–13 | Change the dashboard TTL and an SLA hour value; run `php artisan test` |
