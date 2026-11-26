# 16 — Codebase Learning Map

A recommended **reading order** for the ResolveDesk source code. It starts with simple, standard Laravel code and
ends with the workflow engine, background work and testing. Do the steps in order: each step assumes the
previous ones.

How to use this map:

- Open the listed files side by side with the guide.
- Do the **exercise** at the end of each step. Undo your change afterwards (or keep a backup copy of the file).
- Run the tests after each exercise:

```bash
export PATH="/c/php-8.4.28:$PATH"   # Git Bash (PowerShell: $env:Path = "C:\php-8.4.28;$env:Path")
php -v                              # must show 8.4.28
php artisan test                    # uses the separate resolvedesk_testing database
```

Estimated time: about 1 hour for steps 1–10, 2 hours for steps 11–17, 2 hours for steps 18–25.

```mermaid
flowchart LR
    S1["1-3 Structure, routes, layouts"] --> S2["4-6 Category CRUD, migrations, models"]
    S2 --> S3["7-10 Auth, middleware, policies, users"]
    S3 --> S4["11-17 Tickets, assignment, lifecycle, SLA, activity, comments"]
    S4 --> S5["18-23 Notifications, polling, email, queue, cache, dashboard"]
    S5 --> S6["24-25 Testing, debugging"]
```

---

## Step 1 — Application structure

**Files to read:** `composer.json`, `.env.example`, `bootstrap/app.php`, the folder tree of `app/`
(`Http/Controllers`, `Http/Middleware`, `Models`, `Policies`, `Services`, `Notifications`, `Jobs`, `Mail`,
`Providers`), `resources/views/`, `public/js/`, `database/`, `tests/Feature/`.

**Why they exist:** Laravel's conventional folders tell you where each kind of code lives. `bootstrap/app.php` is
where the application is configured in Laravel 11+ (routing files, middleware aliases, redirects).

**Concepts:** MVC, convention over configuration, environment configuration (`.env`), PSR-4 autoloading
(`App\` → `app/`).

**Related files:** `README.md`, [01-PROJECT-OVERVIEW.md](01-PROJECT-OVERVIEW.md),
[04-LARAVEL-MVC-GUIDE.md](04-LARAVEL-MVC-GUIDE.md).

**Understand before continuing:** which folder holds controllers, models, views; what `.env` is for; that
`QUEUE_CONNECTION`, `CACHE_STORE` and sessions use the database.

**Exercise:** Without opening files, write down where you would look for (a) the ticket status list,
(b) the login page HTML, (c) the email job. Then check: `app/Models/Ticket.php` (`STATUSES`),
`resources/views/auth/login.blade.php`, `app/Jobs/SendTicketEmail.php`.

## Step 2 — Routes

**Files:** `routes/web.php`.

**Why:** every URL maps to a controller method here, together with its middleware and route name.

**Concepts:** `Route::get/post`, `Route::resource(...)->except([...])`, route names (`->name('tickets.assign')`),
route groups with `middleware('guest')`, `middleware(['auth', 'active'])`, `middleware('role:admin')`, route model
binding (`{ticket}` → `Ticket $ticket`), why data-changing actions are POST (CSRF).

**Related:** `app/Http/Controllers/*`, `bootstrap/app.php` (aliases `role`, `active`).

**Understand:** the three route groups (guest, logged-in active users, admins only); that there is no
`tickets.destroy` and no `users.destroy` route.

**Exercise:** Run `php artisan route:list --except-vendor`. Find the route name, HTTP method and middleware for
"Auto Assign" (`tickets.auto-assign`, POST, inside `role:admin`).

## Step 3 — Blade layouts

**Files:** `resources/views/layouts/app.blade.php`, `resources/views/layouts/guest.blade.php`,
`resources/views/partials/navbar.blade.php`, `partials/sidebar.blade.php`, `partials/flash.blade.php`,
`partials/ticket-badges.blade.php`, `public/css/app.css`, `public/js/app.js`.

**Why:** one shared layout = the same navbar, sidebar, flash messages, CSRF meta tag and scripts on every page.

**Concepts:** `@extends`, `@section/@yield`, `@include`, `@hasSection`, `@stack('scripts')`, `{{ }}` escaping,
`@csrf`, `asset()`, `route()`, `request()->routeIs()`, `auth()->user()`; `data-confirm` forms handled by `app.js`.

**Related:** every view in `resources/views/`.

**Understand:** where flash messages (`success`, `error`, `info`) are printed; why the bell has `data-*` URLs.

**Exercise:** Add a "Help" link to the sidebar that points to `route('dashboard')`. Reload any page. Remove it.

## Step 4 — Category CRUD (the simplest feature)

**Files:** `app/Http/Controllers/CategoryController.php`, `resources/views/categories/index.blade.php`,
`create.blade.php`, `edit.blade.php`, `_form.blade.php`.

**Why:** a complete, plain CRUD with no service class — the best first feature to read.

**Concepts:** resource controller methods (index/create/store/edit/update/destroy), `$request->validate()`,
`unique:categories,name`, `Rule::unique(...)->ignore($category->id)`, `$request->boolean('is_active')`,
`withCount('tickets')`, `paginate(15)`, redirect with flash message, business rule "deactivate instead of delete",
`clearCaches()`.

**Related:** `app/Models/Category.php`, `tests/Feature/UserAndCategoryManagementTest.php`.

**Understand:** the request → validation → model → redirect cycle; why `old()` refills the form after errors.

**Exercise:** Change the category name max length from 100 to 60 in `store()` and `update()`. Try a 70-character
name in the browser. Revert.

## Step 5 — Migrations

**Files:** `database/migrations/0001_01_01_000000_create_users_table.php`,
`2026_11_25_000001_create_categories_table.php`, `2026_11_25_000002_create_tickets_table.php`,
`2026_11_25_000003_create_ticket_comments_table.php`, `2026_11_25_000004_create_ticket_activities_table.php`,
`2026_11_25_140742_create_notifications_table.php`, `0001_01_01_000001_create_cache_table.php`,
`0001_01_01_000002_create_jobs_table.php`.

**Why:** migrations are version-controlled database schema.

**Concepts:** `foreignId()->constrained()->restrictOnDelete()`, nullable `assigned_to`, unique `ticket_number`,
indexes (`status`, `priority`, `due_at`, `created_at`, `['assigned_to', 'status']`), why `due_at`/`resolved_at`
are `dateTime` (MariaDB implicit `ON UPDATE CURRENT_TIMESTAMP` on TIMESTAMP columns), `morphs('notifiable')`.

**Related:** [03-DATABASE-GUIDE.md](03-DATABASE-GUIDE.md).

**Understand:** each table's purpose and its foreign keys; that `restrictOnDelete` blocks deleting a used category.

**Exercise:** Run `php artisan migrate:status`. Then draw the five main tables and their foreign keys on paper.
(Do **not** run `migrate:fresh` on data you want to keep — it drops every table.)

## Step 6 — Eloquent models

**Files:** `app/Models/User.php`, `Category.php`, `Ticket.php`, `TicketComment.php`, `TicketActivity.php`.

**Why:** models are PHP classes for tables; they hold relationships, casts, constants and small business helpers.

**Concepts:** `$fillable` (mass-assignment protection — `status`, `assigned_to`, `created_by` are **not** fillable
on `Ticket`), `$hidden`, `casts()` (`'password' => 'hashed'`, `'due_at' => 'datetime'`, integer casts),
constants (`Ticket::STATUSES`, `PRIORITIES`, `SLA_HOURS`, `TRANSITIONS`, `ACTIVE_STATUSES`, `WORKLOAD_STATUSES`,
`User::ROLES`), helper methods (`statusLabel()`, `isOverdue()`, `canPerform()`), local scopes (`scopeOverdue`,
`scopeVisibleTo`).

**Related:** `database/factories/*`, `app/Providers/AppServiceProvider.php` (`Model::preventLazyLoading()`).

**Understand:** why constants live in the model (one place to change); difference between `Ticket::overdue()` (SQL)
and `$ticket->isOverdue()` (PHP on one row).

**Exercise:** In `php artisan tinker`: `App\Models\Ticket::overdue()->count()` and
`App\Models\Ticket::overdue()->toSql()`. Compare with the "Overdue" card on the admin dashboard.

## Step 7 — Authentication

**Files:** `app/Http/Controllers/AuthController.php`, `resources/views/auth/login.blade.php`,
`resources/views/layouts/guest.blade.php`.

**Why:** session-based login without a starter kit, written by hand so every line is explainable.

**Concepts:** `Auth::attempt()`, password hashing (bcrypt), `RateLimiter` (5 attempts, 60 s lockout, key =
email + IP), blocking inactive users after a correct password, `session()->regenerate()` (session fixation),
logout with `invalidate()` + `regenerateToken()`, `redirect()->intended()`, `ValidationException::withMessages`.

**Related:** `tests/Feature/AuthTest.php`, `routes/web.php` (guest group).

**Understand:** what is stored in the `sessions` table; why logout is a POST form.

**Exercise:** Log in as `tanvir.customer@example.com` / `password` (inactive) and read the error. Find the exact
line that produces it.

## Step 8 — Middleware

**Files:** `app/Http/Middleware/RoleMiddleware.php`, `app/Http/Middleware/ActiveUserMiddleware.php`,
`bootstrap/app.php`.

**Why:** cross-cutting checks that run before controllers: "is this an admin?", "is this account still active?".

**Concepts:** `handle(Request $request, Closure $next)`, variadic parameters (`string ...$roles` from
`role:admin,agent`), `abort(403)`, logging out an already-logged-in deactivated user, `expectsJson()` → 401 JSON
for AJAX polling.

**Related:** `routes/web.php`, `resources/views/errors/403.blade.php`.

**Understand:** middleware = coarse "who may enter this URL group"; policies (next step) = fine "may this user do
this to this ticket".

**Exercise:** Log in as a customer and open `/users`. You get 403 from `RoleMiddleware`. Which line in
`routes/web.php` attached it?

## Step 9 — Policies

**Files:** `app/Policies/TicketPolicy.php`, the `@can` blocks in `resources/views/tickets/show.blade.php`, the
`Gate::authorize()` calls in `TicketController.php` and `TicketCommentController.php`.

**Why:** one place for ticket-level permission rules, used by both views (hide buttons) and controllers
(enforce).

**Concepts:** policy auto-discovery (`Ticket` → `TicketPolicy`), `Gate::authorize()` throws 403,
`@can('resolve', $ticket)`, role + ownership + status checks (`canPerform()` from `Ticket::TRANSITIONS`).

**Related:** `tests/Feature/AuthorizationTest.php`.

**Understand:** hiding a button is only UX; the controller check is the real security.

**Exercise:** As customer John, open a ticket of another customer by changing the ID in the URL. Which policy
method returned false? (`view`.)

## Step 10 — User management

**Files:** `app/Http/Controllers/UserController.php`, `resources/views/users/*.blade.php`.

**Why:** admin-only CRUD with extra safety rules.

**Concepts:** search + filters with `when`-style `if ($request->filled(...))`, optional password on edit,
`Rule::in(array_keys(User::ROLES))`, `toggleActive()` (PATCH), `ensureRoleOrStatusChangeIsAllowed()` (an admin cannot
deactivate/demote themselves; the last active admin is protected; an agent with Assigned, In Progress or Resolved
tickets must hand them over first).

**Related:** `tests/Feature/UserAndCategoryManagementTest.php`.

**Understand:** why users are deactivated, never deleted (tickets reference them with `restrictOnDelete`).

**Exercise:** Try to deactivate agent Sarah Ahmed while she has active tickets. Read the error and find the
`ValidationException::withMessages` that produced it.

## Step 11 — Ticket creation

**Files:** `TicketController::create()` and `store()`, `TicketService::createTicket()`,
`resources/views/tickets/create.blade.php`, `tickets/_form.blade.php`.

**Why:** the first use of the service class and a transaction.

**Concepts:** constructor dependency injection (`private TicketService $ticketService`), validation with
`Rule::exists('categories', 'id')->where('is_active', true)`, `DB::transaction()`, setting protected fields
explicitly (`created_by`, `status`, `due_at`), insert-then-number (`Ticket::makeTicketNumber($ticket->id)` →
`TK-000033`), first activity row, "New ticket created" notification + queued email to every active admin
(`notifyUser()` loop), `clearDashboardCache()`.

**Related:** [06-BUSINESS-LOGIC-GUIDE.md](06-BUSINESS-LOGIC-GUIDE.md), `tests/Feature/TicketManagementTest.php`.

**Understand:** why the ticket number comes from the database id and not `MAX(id) + 1`.

**Exercise:** Create a Critical ticket. Check that `due_at` is exactly `created_at + 2 hours` on the details page.

## Step 12 — Relationships and eager loading

**Files:** relationship methods in `User.php` (`createdTickets`, `assignedTickets`, ...), `Ticket.php` (`creator`,
`assignedAgent`, `category`, `comments`, `activities`), `TicketController::index()` (`with([...])`) and `show()`
(`load([...])`), `TicketController::index()` + `Ticket::scopeVisibleTo()`.

**Why:** the ticket list and details page show data from several tables efficiently.

**Concepts:** `belongsTo` with a custom foreign key (`'created_by'`, `'assigned_to'`), `hasMany`, nullable
relation, eager loading vs lazy loading, the **N+1 problem**, `Model::preventLazyLoading()`, `withCount()`,
`paginate()->withQueryString()`.

**Related:** `app/Providers/AppServiceProvider.php`.

**Understand:** 15 tickets per page with `with(['category','creator','assignedAgent'])` = 1 + 3 queries, not
1 + 45.

**Exercise:** Temporarily remove `'creator'` from the `with([...])` in `TicketController::index()` and open
`/tickets`. You get a `LazyLoadingViolationException` — that is `preventLazyLoading` protecting you. Revert.

## Step 13 — Assignment (manual and automatic)

**Files:** `TicketController::assign()`, `autoAssign()`, `agentList()`; `TicketService::assignAgent()`,
`autoAssign()`, `findLeastBusyAgent()`, `performAssignment()`, `lockTicket()`.

**Why:** the core workflow; shows transactions, row locking and a query-based algorithm.

**Concepts:** `lockForUpdate()` (`SELECT ... FOR UPDATE`), re-checking rules on the locked row, re-reading the agent
from the database, duplicate prevention (`return false` for the same agent), `withCount` + `orderBy('workload_count')`
+ `orderBy('id')` tie-breaker, reassignment keeps the status.

**Related:** `tests/Feature/TicketWorkflowTest.php`, [06-BUSINESS-LOGIC-GUIDE.md](06-BUSINESS-LOGIC-GUIDE.md).

**Understand:** why two admins clicking Auto Assign at the same moment cannot both assign the same ticket.

**Exercise:** In tinker run `app(App\Services\TicketService::class)->findLeastBusyAgent()->name` on fresh seed data
(expected: Michael Brown, workload 2). Predict who is next after one auto-assignment (Fatima Khan and Michael both
have 3 → lower id wins: Fatima).

## Step 14 — Status transitions

**Files:** `Ticket::TRANSITIONS`, `canPerform()`; `TicketService::startWork()`, `resolve()`, `reopen()`, `close()`,
`ensureActionAllowed()`; `TicketController::start()`, `resolve()`, `reopen()`, `close()`; the workflow buttons in
`tickets/show.blade.php`.

**Why:** a small state machine: only valid status changes are possible.

**Concepts:** transition table keyed by action, policy + service double check, `ValidationException` for business
rule failures (redirect back with error), resolution note required, reopen clears `resolved_at` and the note,
closed = read-only.

**Related:** `TicketPolicy` (`start`, `resolve`, `close`, `reopen`).

**Understand:** why the edit form can never change status.

**Exercise:** Add a constant comment above `TRANSITIONS` describing a new action "hold" (In Progress → Open). List
every file you would need to change (constant, policy method, service method, controller method, route, button,
test). Do not implement it.

## Step 15 — SLA

**Files:** `Ticket::SLA_HOURS`, `calculateDueAt()`, `isOverdue()`, `scopeOverdue()`;
`TicketService::updateTicket()` (priority change recalculates from original `created_at`); overdue badges in
`tickets/index.blade.php` and `tickets/show.blade.php`.

**Why:** deadlines per priority; overdue is computed live.

**Concepts:** Carbon (`copy()->addHours()`), `now()->greaterThan()`, timezone `Asia/Dhaka`, why overdue is never
stored or cached, reopened tickets keep their original deadline (simplified rule).

**Related:** `config/app.php` (timezone).

**Understand:** "continuous calendar hours" — no business hours or holidays.

**Exercise:** Change High from 8 to 12 hours in `SLA_HOURS`. Create a High ticket and check `due_at`. Revert and
run the tests.

## Step 16 — Activity logging

**Files:** `TicketService::recordActivity()`, `app/Models/TicketActivity.php`, the timeline in
`tickets/show.blade.php`.

**Why:** an audit trail: who did what and when.

**Concepts:** one row per event (`created`, `assigned`, `reassigned`, `status_changed`, `priority_changed`,
`due_date_changed`, `updated`, `resolved`, `reopened`, `closed`), `old_value`/`new_value`, written in the same
transaction as the change.

**Related:** `DatabaseSeeder::activity()` (past timestamps).

**Understand:** if the transaction rolls back, the activity row disappears too.

**Exercise:** Change a ticket's priority from Medium to High as admin. Find the two new activity rows
(`priority_changed`, `due_date_changed`) in the timeline.

## Step 17 — Comments

**Files:** `app/Http/Controllers/TicketCommentController.php`, `TicketService::addComment()`,
`app/Models/TicketComment.php`, the conversation section of `tickets/show.blade.php`.

**Why:** customer–agent conversation with notification rules.

**Concepts:** `Gate::authorize('comment', $ticket)`, `max:5000` validation, closed tickets reject comments (policy
and service), recipients = creator + agent minus author, `$alreadyNotifiedIds`, `mb_strimwidth`, redirect to
`#conversation`.

**Related:** `tests/Feature/NotificationTest.php`.

**Understand:** why the service re-checks `isClosed()` even though the policy already did (race: the ticket may be
closed between page load and submit).

**Exercise:** As customer John, comment on one of his In Progress tickets. Log in as the assigned agent and check
the bell.

## Step 18 — Notifications

**Files:** `app/Notifications/TicketNotification.php`, `TicketService::notifyUser()`,
`app/Http/Controllers/NotificationController.php`, `resources/views/notifications/index.blade.php`.

**Why:** in-app notifications stored in the database.

**Concepts:** Notification class, `via()` → `['database']`, `toArray()`, `Notifiable` trait, polymorphic
`notifiable_type/notifiable_id`, `read_at`, authorization by query scope (`$request->user()->notifications()`),
`expectsJson()`.

**Related:** [13-NOTIFICATION-SYSTEM-GUIDE.md](13-NOTIFICATION-SYSTEM-GUIDE.md).

**Understand:** notifications are not queued and are part of the transaction.

**Exercise:** In tinker: `App\Models\User::where('email','sarah.agent@resolvedesk.test')->first()->unreadNotifications()->count()`.
Compare with Sarah's badge.

## Step 19 — AJAX polling

**Files:** `public/js/notifications.js`, `resources/views/partials/navbar.blade.php`,
`resources/views/layouts/app.blade.php`, `NotificationController::feed()`.

**Why:** near-real-time updates without page reload.

**Concepts:** IIFE, Fetch API, `async/await`, recursive `setTimeout`, `isRequestRunning`, `?since=server_time`,
`Set` for de-duplication, `textContent` (XSS), CSRF header, `visibilitychange`, 401/419 handling, Bootstrap Toast.

**Related:** [13-NOTIFICATION-SYSTEM-GUIDE.md](13-NOTIFICATION-SYSTEM-GUIDE.md) §10.

**Understand:** why `setInterval` was not used.

**Exercise:** Open DevTools → Network, filter "feed". Watch a request every 5 s. Switch to another tab for a
minute and come back: the interval was 30 s while hidden and a request fires immediately on return.

## Step 20 — Email

**Files:** `app/Mail/TicketUpdateMail.php`, `resources/views/emails/ticket-update.blade.php`, `config/mail.php`,
`.env` (`MAIL_MAILER=log`).

**Why:** one reusable email for all ticket events.

**Concepts:** Mailable `envelope()` / `content()`, named arguments, table-based inline-styled email HTML, log mailer,
Gmail SMTP + App Password (documented only).

**Related:** [14-QUEUE-AND-EMAIL-GUIDE.md](14-QUEUE-AND-EMAIL-GUIDE.md).

**Understand:** the email link does not grant access; `TicketPolicy::view` still applies.

**Exercise:** Add "Priority" to the email: add the key in `TicketService::queueEmail()`, pass it in `content()`, show
it in the template; update `test_email_template_renders_ticket_information`.

## Step 21 — Queue

**Files:** `app/Jobs/SendTicketEmail.php`, `TicketService::queueEmail()`, `config/queue.php`,
`database/migrations/0001_01_01_000002_create_jobs_table.php`.

**Why:** emails are sent asynchronously by a worker.

**Concepts:** `ShouldQueue`, `Queueable`, serialization, `$tries = 3`, `$backoff = 10`, `failed()`,
`DB::afterCommit()` + try/catch + `Log::error`, `jobs` / `failed_jobs`, `queue:work`, `queue:failed`,
`queue:retry`, `queue:restart`, at-least-once delivery.

**Related:** `tests/Feature/QueueAndEmailTest.php`.

**Understand:** what happens to emails when no worker runs (they wait) and on rollback (never created).

**Exercise:** Without a worker, assign a ticket. Count rows in `jobs`. Run `php artisan queue:work --stop-when-empty`
and find the email at the end of `storage/logs/laravel.log`.

## Step 22 — Caching

**Files:** `DashboardController::adminDashboard()`, `Category::activeForDropdown()`, `clearActiveCache()`,
`TicketService::clearDashboardCache()`, `CategoryController::clearCaches()`.

**Why:** avoid repeating expensive aggregate queries.

**Concepts:** cache-aside, `Cache::remember()`, `Cache::forget()`, TTL, database cache store, stale data, never
validating against cache.

**Related:** [15-CACHING-GUIDE.md](15-CACHING-GUIDE.md), `tests/Feature/CacheTest.php`.

**Understand:** what is cached and what is deliberately not (overdue, workload, unread count, permissions).

**Exercise:** Open the admin dashboard twice and note "Statistics generated at". Create a ticket in another window,
refresh the dashboard, and see the time change.

## Step 23 — Dashboard queries

**Files:** `app/Http/Controllers/DashboardController.php` (all three dashboards),
`resources/views/dashboard/admin.blade.php`, `agent.blade.php`, `customer.blade.php`, `_stat-card.blade.php`.

**Why:** reporting with real SQL aggregates.

**Concepts:** `select(... DB::raw('COUNT(*) AS total'))->groupBy()->pluck()`, filling missing statuses with 0,
`withCount` with conditional closures (`active_count`, `overdue_count`, `resolved_count`), `DATE(created_at)` trend,
`AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))`, Chart.js from local `public/vendor/chartjs/`.

**Related:** [10-SYSTEM-DESIGN-GUIDE.md](10-SYSTEM-DESIGN-GUIDE.md).

**Understand:** which numbers are cached and which are live on the admin dashboard.

**Exercise:** Add a stat "Critical open tickets" to the **agent** dashboard
(`Ticket::where('assigned_to', $agent->id)->where('priority', 'critical')->whereIn('status', Ticket::ACTIVE_STATUSES)->count()`)
and display it with `_stat-card`.

## Step 24 — Testing

**Files:** `phpunit.xml`, `tests/TestCase.php`, `tests/Feature/*.php`, `database/factories/*.php`.

**Why:** proves the rules work and protects you when you modify code in the interview.

**Concepts:** `RefreshDatabase`, separate `resolvedesk_testing` database, factories with states
(`User::factory()->agent()`, `Ticket::factory()->inProgress($agent)`), `actingAs()`, `assertSessionHas`,
`assertForbidden`, `Queue::fake()`, `Mail::fake()`, `Cache::has()`, `travel()`; test env uses `QUEUE_CONNECTION=sync`,
`CACHE_STORE=array`, `MAIL_MAILER=array`.

**Related:** [08-LIVE-CODING-PRACTICE.md](08-LIVE-CODING-PRACTICE.md).

**Understand:** how to run one test: `php artisan test --filter=test_auto_assign`.

**Exercise:** Write a test: "a customer cannot auto-assign a ticket" (expect 403). Run only that test.

## Step 25 — Debugging

**Files:** `storage/logs/laravel.log`, `.env` (`APP_DEBUG=true` locally), `app/Providers/AppServiceProvider.php`.

**Why:** in an interview you will be asked to find and fix a bug quickly.

**Concepts:** reading the stack trace, `dd()` / `dump()`, `php artisan tinker`, `php artisan route:list`,
`DB::enableQueryLog()`, browser DevTools (Network + Console), `php artisan optimize:clear`, checking `php -v`,
queue/caching pitfalls (stale worker code, stale cache).

**Related:** [11-DEBUGGING-GUIDE.md](11-DEBUGGING-GUIDE.md), [12-QUICK-REFERENCE.md](12-QUICK-REFERENCE.md).

**Understand:** the request lifecycle well enough to guess which layer an error comes from (route 404, middleware
403, policy 403, validation 302 with errors, CSRF 419, server 500).

**Exercise:** Break `Ticket::TRANSITIONS['resolve']['from']` to `self::STATUS_ASSIGNED`. Run
`php artisan test --filter=TicketWorkflowTest`. Read the failures, explain them, and revert.

---

## Quick self-check after all 25 steps

You should be able to answer, without looking:

1. What happens, file by file, when an admin clicks **Auto Assign**?
2. Where is the rule "only Resolved tickets can be closed" enforced? (Two places.)
3. Why does an email never go out for a rolled-back assignment?
4. Why is the overdue count not cached?
5. How does the agent's browser learn about a new assignment without a reload?
6. What prevents a customer from reading another customer's ticket or notification?
