# 12 — Quick Reference (interview cheat sheet)

One-page revision sheet for ResolveDesk. Details: [System design](10-SYSTEM-DESIGN-GUIDE.md) ·
[Debugging](11-DEBUGGING-GUIDE.md) · [Business logic](06-BUSINESS-LOGIC-GUIDE.md).

**Stack:** Laravel 13.44.1 · PHP 8.4.28 (`C:\php-8.4.28\php.exe`) · MariaDB 10.4 (MySQL 8 compatible) ·
Blade + Bootstrap 5.3 + Vanilla JS · database driver for session, cache and queue · `MAIL_MAILER=log`.

**Demo logins** (local only, password `password`): `admin@resolvedesk.test` · `sarah.agent@resolvedesk.test`
(also david/fatima/michael) · `john.customer@example.com` (also emily/rahim/olivia; `tanvir` is inactive).

---

## 1. Directory map (custom files only)

```text
app/
  Http/Controllers/
    AuthController.php            login / logout, rate limiting
    DashboardController.php       3 role dashboards, admin stats cache
    TicketController.php          ticket CRUD (no destroy) + workflow buttons
    TicketCommentController.php   add comment
    NotificationController.php    history page + JSON feed + mark read
    UserController.php            user CRUD (no destroy) + toggle active
    CategoryController.php        category CRUD (no show)
  Http/Middleware/
    RoleMiddleware.php            alias "role"   -> role:admin
    ActiveUserMiddleware.php      alias "active" -> logs out deactivated users
  Models/  User, Ticket, Category, TicketComment, TicketActivity
  Policies/TicketPolicy.php       per-ticket permissions
  Services/TicketService.php      all coordinated ticket business rules
  Notifications/TicketNotification.php   database channel only (not queued)
  Jobs/SendTicketEmail.php        queued email job ($tries 3, $backoff 10)
  Mail/TicketUpdateMail.php       one reusable Mailable
  Providers/AppServiceProvider.php Bootstrap 5 pagination + preventLazyLoading
bootstrap/app.php                 middleware aliases, guest/user redirects
routes/web.php                    all web routes
database/migrations/              users(+sessions), cache, jobs(+failed_jobs), categories,
                                  tickets, ticket_comments, ticket_activities, notifications
database/seeders/DatabaseSeeder.php   demo data (32 tickets, inactive category "Legacy Desktop App")
database/factories/               UserFactory, TicketFactory, CategoryFactory
resources/views/
  layouts/app.blade.php, guest.blade.php
  partials/navbar, sidebar, flash, ticket-badges
  dashboard/admin, agent, customer, _stat-card
  tickets/index, create, edit, show, _form
  users/..., categories/..., notifications/index
  emails/ticket-update.blade.php
  errors/403.blade.php, 419.blade.php
public/js/notifications.js        AJAX polling, badge, dropdown, toasts
public/js/app.js                  data-confirm dialogs
tests/Feature/                    9 files, 88 tests (DB: resolvedesk_testing)
```

## 2. Artisan commands

Run `export PATH="/c/php-8.4.28:$PATH"` (Git Bash) or `$env:Path = "C:\php-8.4.28;$env:Path"` (PowerShell) first.

| Command | Purpose |
|---|---|
| `php artisan serve` | dev server at http://127.0.0.1:8000 |
| `php artisan migrate` | run new migrations |
| `php artisan migrate:status` | which migrations ran |
| `php artisan migrate:fresh --seed` | **drops all tables**, rebuilds, seeds demo data (ask first!) |
| `php artisan db:seed` | run `DatabaseSeeder` |
| `php artisan route:list --except-vendor` | all app routes (`-v` = with middleware) |
| `php artisan tinker` | interactive shell |
| `php artisan test` | run all tests |
| `php artisan test --filter=TicketWorkflowTest` | run one test class / method |
| `php artisan make:controller XController` | new controller |
| `php artisan make:model X -mf` | model + migration + factory |
| `php artisan make:migration add_x_to_tickets_table` | new migration |
| `php artisan make:policy XPolicy --model=X` | new policy |
| `php artisan db:show` / `db:table tickets` | inspect DB / one table |
| `php artisan optimize:clear` | clear config, route, view, cache |
| `php artisan config:clear` | after editing `.env` (if config was cached) |
| `php artisan view:clear` | delete compiled Blade |

## 3. All routes (`php artisan route:list --except-vendor`, 35 routes)

All routes have the `web` middleware group (session, CSRF). **Group A** = `auth, active`. **Group B** = `auth, active, role:admin`.

| Name | Method | URI | Controller@method | Middleware |
|---|---|---|---|---|
| — | ANY | `/` | RedirectController (→ `/dashboard`) | web |
| `login` | GET | `/login` | AuthController@showLogin | guest |
| `login.attempt` | POST | `/login` | AuthController@login | guest |
| `logout` | POST | `/logout` | AuthController@logout | A |
| `dashboard` | GET | `/dashboard` | DashboardController@index | A |
| `tickets.index` | GET | `/tickets` | TicketController@index | A |
| `tickets.create` | GET | `/tickets/create` | TicketController@create | A |
| `tickets.store` | POST | `/tickets` | TicketController@store | A |
| `tickets.show` | GET | `/tickets/{ticket}` | TicketController@show | A |
| `tickets.edit` | GET | `/tickets/{ticket}/edit` | TicketController@edit | A |
| `tickets.update` | PUT/PATCH | `/tickets/{ticket}` | TicketController@update | A |
| `tickets.start` | POST | `/tickets/{ticket}/start` | TicketController@start | A |
| `tickets.resolve` | POST | `/tickets/{ticket}/resolve` | TicketController@resolve | A |
| `tickets.reopen` | POST | `/tickets/{ticket}/reopen` | TicketController@reopen | A |
| `tickets.close` | POST | `/tickets/{ticket}/close` | TicketController@close | A |
| `tickets.comments.store` | POST | `/tickets/{ticket}/comments` | TicketCommentController@store | A |
| `tickets.assign` | POST | `/tickets/{ticket}/assign` | TicketController@assign | **B** |
| `tickets.auto-assign` | POST | `/tickets/{ticket}/auto-assign` | TicketController@autoAssign | **B** |
| `notifications.index` | GET | `/notifications` | NotificationController@index | A |
| `notifications.feed` | GET | `/notifications/feed` | NotificationController@feed | A |
| `notifications.read-all` | POST | `/notifications/read-all` | NotificationController@markAllAsRead | A |
| `notifications.read` | POST | `/notifications/{id}/read` | NotificationController@markAsRead | A |
| `users.index` | GET | `/users` | UserController@index | **B** |
| `users.create` | GET | `/users/create` | UserController@create | **B** |
| `users.store` | POST | `/users` | UserController@store | **B** |
| `users.show` | GET | `/users/{user}` | UserController@show | **B** |
| `users.edit` | GET | `/users/{user}/edit` | UserController@edit | **B** |
| `users.update` | PUT/PATCH | `/users/{user}` | UserController@update | **B** |
| `users.toggle-active` | PATCH | `/users/{user}/toggle-active` | UserController@toggleActive | **B** |
| `categories.index` | GET | `/categories` | CategoryController@index | **B** |
| `categories.create` | GET | `/categories/create` | CategoryController@create | **B** |
| `categories.store` | POST | `/categories` | CategoryController@store | **B** |
| `categories.edit` | GET | `/categories/{category}/edit` | CategoryController@edit | **B** |
| `categories.update` | PUT/PATCH | `/categories/{category}` | CategoryController@update | **B** |
| `categories.destroy` | DELETE | `/categories/{category}` | CategoryController@destroy | **B** |

Not defined on purpose: `tickets.destroy`, `users.destroy`, `categories.show`.
Plus the framework health route `/up` (vendor).

## 4. Controller methods

| Controller | Method | Does |
|---|---|---|
| AuthController | `showLogin`, `login`, `logout` | rate limit 5 tries / 60 s (`MAX_LOGIN_ATTEMPTS`, `LOCKOUT_SECONDS`), block inactive, `session()->regenerate()` |
| DashboardController | `index` → `adminDashboard()` / `agentDashboard()` / `customerDashboard()`; `buildAdminStatistics()` | admin stats cached 60 s |
| TicketController | `index` (filters + `paginate(15)`), `create`, `store`, `show`, `edit`, `update`, `assign`, `autoAssign`, `start`, `resolve`, `reopen`, `close`; private `agentList()` | `Gate::authorize()` → validate → `TicketService` |
| TicketCommentController | `store` | `Gate::authorize('comment')` → `addComment()` |
| NotificationController | `index`, `feed`, `markAsRead`, `markAllAsRead`; private `formatNotification()` | own notifications only |
| UserController | `index`, `create`, `store`, `show`, `edit`, `update`, `toggleActive`; private `ensureRoleOrStatusChangeIsAllowed()` | no self-deactivate, keep 1 admin, agent hand-over |
| CategoryController | `index`, `create`, `store`, `edit`, `update`, `destroy`; private `clearCaches()` | delete blocked if used |

## 5. TicketService methods (`app/Services/TicketService.php`)

| Method | Visibility | Summary |
|---|---|---|
| `createTicket(array $data, User $creator): Ticket` | public | transaction: insert (status open, `due_at`), set `ticket_number`, activity `created`, notify + email every active admin (`created`); clear dashboard cache |
| `updateTicket(Ticket, array, User): bool` | public | edit subject/description/category/priority; priority change recalculates `due_at` from `created_at`; `false` if nothing changed |
| `assignAgent(Ticket, User $agent, User $admin): bool` | public | lock → `performAssignment()`; `false` if same agent |
| `autoAssign(Ticket, User $admin): ?User` | public | lock, must be Open, `findLeastBusyAgent()`; `null` if no agent |
| `findLeastBusyAgent(): ?User` | public | active agent, fewest assigned+in_progress, tie → lowest id |
| `performAssignment(...)` | private | status check, re-read agent, duplicate check, save, activity, notify + email |
| `startWork(Ticket, User)` | public | assigned → in_progress |
| `resolve(Ticket, string $note, User)` | public | in_progress → resolved, `resolved_at`, note; notify + email creator |
| `reopen(Ticket, User)` | public | resolved → in_progress, clear note/`resolved_at`, agent must be active; notify + email agent |
| `close(Ticket, User)` | public | resolved → closed; in-app only to agent |
| `addComment(Ticket, string, User): TicketComment` | public | not if closed; notify creator + agent except author (in-app only) |
| `lockTicket(Ticket)` | private | `whereKey()->lockForUpdate()->firstOrFail()` |
| `ensureActionAllowed(Ticket, string)` | private | `canPerform()` else `ValidationException` |
| `recordActivity(...)` | private | insert `ticket_activities` row |
| `notifyUser(...)` | private | skip null/inactive/actor; `notify()`; optional `queueEmail()` |
| `queueEmail(...)` | private | `DB::afterCommit` + try/catch + `Log::error` |
| `clearDashboardCache()` | public | `Cache::forget(self::DASHBOARD_CACHE_KEY)` |
| `formatDate($date)` | private | `d M Y, h:i A` |

Constant: `DASHBOARD_CACHE_KEY = 'dashboard:admin:stats'`.

## 6. Models, constants, relationships

| Model | Relationship | Type | Key |
|---|---|---|---|
| User | `createdTickets()` | hasMany Ticket | `created_by` |
| User | `assignedTickets()` | hasMany Ticket | `assigned_to` |
| User | `ticketComments()`, `ticketActivities()` | hasMany | `user_id` |
| User | `notifications()`, `unreadNotifications()` | morphMany (Notifiable trait) | `notifiable_type/id` |
| Ticket | `creator()` | belongsTo User | `created_by` |
| Ticket | `assignedAgent()` | belongsTo User (nullable) | `assigned_to` |
| Ticket | `category()` | belongsTo Category | `category_id` |
| Ticket | `comments()`, `activities()` | hasMany, ordered by `created_at`, `id` | `ticket_id` |
| Category | `tickets()` | hasMany Ticket | `category_id` |
| TicketComment / TicketActivity | `ticket()`, `user()` | belongsTo | |

Key constants / helpers:
- `User::ROLES` (`admin`, `agent`, `customer`), `isAdmin()`, `isAgent()`, `isCustomer()`, `roleLabel()`.
- `Ticket::STATUSES`, `PRIORITIES`, `SLA_HOURS` (low 48, medium 24, high 8, critical 2), `TRANSITIONS`,
  `ACTIVE_STATUSES` (open, assigned, in_progress), `WORKLOAD_STATUSES` (assigned, in_progress).
- `Ticket::calculateDueAt()`, `makeTicketNumber()` (`TK-000007`), `canPerform()`, `isOverdue()`, `isClosed()`,
  `statusLabel()`, `priorityLabel()`; scopes `overdue()`, `visibleTo($user)`.
- `Category::ACTIVE_CACHE_KEY`, `ACTIVE_CACHE_TTL_SECONDS`, `activeForDropdown()`, `clearActiveCache()`.
- `TicketActivity::ACTION_LABELS`, `actionLabel()`.
- `$fillable` — Ticket: subject, description, category_id, priority (**not** status). TicketComment: message only.

> **বাংলায়:** `belongsTo` = এই row অন্য table-এর একটা row-কে point করে (ticket → creator)। `hasMany` = উল্টো দিক
> (user → অনেক ticket)। Notification-এর `notifiable` polymorphic — একটা column জোড়া (`type` + `id`) দিয়ে যেকোনো
> model-কে point করা যায়।

## 7. Common queries

```php
Ticket::with(['category', 'creator', 'assignedAgent'])->visibleTo($user)->latest()->paginate(15)->withQueryString();
Ticket::overdue()->count();                                     // status active AND due_at < now()
Ticket::where('assigned_to', $agent->id)->where('status', Ticket::STATUS_IN_PROGRESS)->count();
$ticket->load(['category', 'creator', 'assignedAgent', 'comments.user', 'activities.user']);
User::where('role', User::ROLE_AGENT)->where('is_active', true)
    ->withCount(['assignedTickets as workload_count' => fn ($q) => $q->whereIn('status', Ticket::WORKLOAD_STATUSES)])
    ->orderBy('workload_count')->orderBy('id')->first();
Ticket::select('status', DB::raw('COUNT(*) AS total'))->groupBy('status')->pluck('total', 'status');
Category::withCount('tickets')->orderBy('name')->paginate(15);
Ticket::whereKey($id)->lockForUpdate()->firstOrFail();           // inside DB::transaction()
$query->where(fn ($q) => $q->where('created_by', $id)->orWhere('assigned_to', $id)); // brackets around OR
$user->unreadNotifications()->count();
```

## 8. Validation syntax (real rules)

```php
$request->validate([
    'subject'     => ['required', 'string', 'max:255'],
    'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
    'priority'    => ['required', Rule::in(array_keys(Ticket::PRIORITIES))],
], ['category_id.exists' => 'Please choose an active category.']);   // custom message

'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
'password' => ['nullable', 'string', 'min:8', 'confirmed'],   // needs password_confirmation field
'date_to'  => ['nullable', 'date', 'after_or_equal:date_from'],
'since'    => ['nullable', 'date'],                           // feed: invalid → 422 JSON
$validated['is_active'] = $request->boolean('is_active');     // unchecked checkbox → false

throw ValidationException::withMessages(['ticket' => 'This action is not allowed ...']); // business rule
```

Failure: HTML form → 302 back + `$errors` + `old()`. JSON request → 422.

## 9. Blade syntax used in the project

| Syntax | Example / meaning |
|---|---|
| `{{ $x }}` | escaped output (XSS-safe) |
| `@extends('layouts.app')`, `@section('content')`, `@yield('content')` | layout inheritance |
| `@include('partials.ticket-badges', ['ticket' => $ticket])` | partial with data |
| `@push('scripts')` / `@stack('scripts')` | page-specific JS (admin charts) |
| `@csrf` | hidden `_token` (missing → 419) |
| `@method('PUT')` / `@method('DELETE')` | method spoofing for edit/delete forms |
| `@can('resolve', $ticket) ... @endcan` | show button only if `TicketPolicy@resolve` allows |
| `@error('subject') is-invalid @enderror`, `{{ $message }}` | field error |
| `old('subject', $ticket->subject)` | previous input after failed validation |
| `@forelse ($tickets as $ticket) ... @empty ... @endforelse` | loop with empty state |
| `@php($only = $only ?? null)` | small inline PHP |
| `@json($stats['trend'])` | PHP array → JS (admin dashboard) |
| `@@json` | literal `@json` text (used in a JS comment — bug fix) |
| `@checked(...)`, `@selected(...)` | form state |
| `{{ $tickets->links() }}` | Bootstrap 5 pagination |
| `route('tickets.show', $ticket)`, `asset('js/notifications.js')`, `csrf_token()` | helpers |

## 10. Business rules

| Rule | Where |
|---|---|
| Lifecycle: open → assigned (assignment) → in_progress (start) → resolved (resolve) → closed (close); resolved → in_progress (reopen) | `Ticket::TRANSITIONS`, `TicketService` |
| Closed = read-only (no edit, comment, transition) | `TicketPolicy::update/comment`, `addComment()` |
| SLA `due_at` = `created_at` + low 48h / medium 24h / high 8h / critical 2h | `Ticket::SLA_HOURS`, `calculateDueAt()` |
| Priority change recalculates `due_at` from original `created_at` | `updateTicket()` |
| Reopen keeps original `due_at` | `reopen()` |
| Overdue = active status AND `due_at` past (live, never stored) | `isOverdue()`, `scopeOverdue()` |
| Ticket number `TK-` + 6-digit id | `makeTicketNumber()` |
| Who: assign/auto-assign = admin; start/resolve = assigned agent or admin; close/reopen = creator or admin | `TicketPolicy` |
| Creator can edit only while Open and unassigned; admin any non-closed | `TicketPolicy::update` |
| Customer/agent see tickets they created or are assigned to; admin sees all | `TicketPolicy::view`, `scopeVisibleTo()` |
| Auto-assign only for Open; least workload (assigned + in_progress), tie lowest id; none → stays Open | `autoAssign()`, `findLeastBusyAgent()` |
| Reassign allowed for Open/Assigned/In Progress, keeps status; same agent = no change | `performAssignment()` |
| Resolve needs a resolution note | `resolve()` + `'resolution_note' => ['required', ...]` |
| Reopen needs an active assigned agent | `reopen()` |
| New ticket category must exist AND be active (checked in DB) | `TicketController@store/update` |
| Category used by tickets cannot be deleted | `CategoryController@destroy` + `restrictOnDelete()` |
| Users never deleted; admin can't deactivate/demote self; ≥1 active admin; agent with assigned/in_progress/resolved tickets can't be deactivated/demoted | `UserController` |
| Inactive user can't log in and is logged out on next request | `AuthController@login`, `ActiveUserMiddleware` |
| Login: 5 failed attempts per email+IP → 60 s lockout | `AuthController` |

## 11. Notification operations

| Event | In-app to | Email? |
|---|---|---|
| created | every active admin (not the creator) | yes |
| assigned | agent | yes |
| reassigned | new agent | yes |
| resolved | creator | yes |
| reopened | assigned agent | yes |
| closed | assigned agent | no |
| comment | creator + agent, except author | no |

Never notified: the actor themself, inactive users, a missing (null) agent.

```php
$user->notify(new TicketNotification($ticket, 'assigned', $title, $message)); // inserts notifications row
$user->notifications()->paginate(15);          // history (newest first)
$user->unreadNotifications()->count();         // badge
$user->notifications()->findOrFail($id)->markAsRead();
$user->unreadNotifications()->update(['read_at' => now()]);   // mark all
$notification->data['ticket_id'];              // JSON column, cast to array
```

JS (`public/js/notifications.js`): `POLL_INTERVAL_MS = 5000`, `HIDDEN_POLL_INTERVAL_MS = 30000`,
`GET /notifications/feed?since=<server_time>`, `shownToastIds` Set, `X-CSRF-TOKEN` header on POST,
401/419 → `handleExpiredSession()`.

Feed JSON: `{ unread_count, notifications[5], new_notifications[≤20], server_time }`.

## 12. Queue and email

| Item | Value |
|---|---|
| Connection | `QUEUE_CONNECTION=database` (tests: `sync`) |
| Tables | `jobs`, `failed_jobs` |
| Job | `App\Jobs\SendTicketEmail` — `$tries = 3`, `$backoff = 10`, `failed()` logs |
| Mailable / view | `App\Mail\TicketUpdateMail` / `emails.ticket-update`; subject `[TK-000005] New ticket assigned` |
| Dispatch | after commit only (`DB::afterCommit`), errors logged |
| `retry_after` | 90 s (`config/queue.php`) |

```bash
php artisan queue:work                    # start consumer (keep terminal open)
php artisan queue:work --stop-when-empty  # process waiting jobs then exit
php artisan queue:restart                 # after code changes
php artisan queue:failed                  # list failed
php artisan queue:retry all               # or: queue:retry <uuid>
php artisan queue:forget <uuid>           # delete one failed record
php artisan queue:flush                   # delete all failed records
php artisan queue:clear                   # delete waiting jobs (careful)
```

Emails with `MAIL_MAILER=log` → `storage/logs/laravel.log`.

## 13. Cache

| Key | TTL | Set by | Cleared by |
|---|---|---|---|
| `dashboard:admin:stats` | 60 s | `DashboardController::adminDashboard()` | `TicketService::clearDashboardCache()` (every ticket write except comment), `CategoryController::clearCaches()` |
| `ticket:active-categories` | 3600 s | `Category::activeForDropdown()` | `Category::clearActiveCache()` via `CategoryController::clearCaches()` |

Store: `CACHE_STORE=database` (`cache` table, row key prefixed `resolvedesk-cache-`); tests: `array`.
Not cached: overdue count, recent tickets, agent workload, agent/customer dashboards, notifications, permissions.

```bash
php artisan cache:clear
```
```php
Cache::remember($key, $seconds, fn () => ...);   // cache-aside
Cache::get($key); Cache::has($key); Cache::forget($key);
```

> **বাংলায়:** Cache-aside: আগে cache দেখো (hit হলে সেটাই), না থাকলে (miss) DB থেকে হিসাব করে রেখে দাও। Data
> বদলালে `Cache::forget()` দিয়ে পুরনো মান মুছে দাও — পরের request নতুন করে বানাবে।

## 14. Frequently modified files — "to change X, edit Y"

| To change… | Edit |
|---|---|
| SLA hours | `Ticket::SLA_HOURS` in `app/Models/Ticket.php` |
| Add/rename a status or transition | `Ticket::STATUSES`, `TRANSITIONS` (+ `TicketPolicy`, `TicketService`, `resources/views/partials/ticket-badges.blade.php`, `TicketActivity::ACTION_LABELS`) |
| Who may do an action | `app/Policies/TicketPolicy.php` |
| Admin-only routes | `routes/web.php` `role:admin` group |
| What happens on assign/resolve/... | `app/Services/TicketService.php` |
| Who gets notified / emailed | the `notifyUser(..., $sendEmail)` calls in `TicketService` |
| Auto-assign algorithm | `TicketService::findLeastBusyAgent()` (+ `Ticket::WORKLOAD_STATUSES`) |
| Ticket number format | `Ticket::makeTicketNumber()` |
| Tickets per page | `TicketController::PER_PAGE` |
| Ticket list filters | `TicketController@index` + `resources/views/tickets/index.blade.php` |
| Ticket form validation | `TicketController@store` / `update` |
| Polling interval | `POLL_INTERVAL_MS` in `public/js/notifications.js` |
| Dropdown size | `NotificationController::DROPDOWN_LIMIT` |
| Dashboard cache time | `DashboardController::ADMIN_STATS_TTL_SECONDS` |
| Category cache time | `Category::ACTIVE_CACHE_TTL_SECONDS` |
| Email retries / delay | `SendTicketEmail::$tries`, `$backoff` |
| Email text / layout | `resources/views/emails/ticket-update.blade.php`, `TicketUpdateMail::envelope()` |
| Login attempt limit | `AuthController::MAX_LOGIN_ATTEMPTS`, `LOCKOUT_SECONDS` |
| Sidebar menu | `resources/views/partials/sidebar.blade.php` |
| Flash/error messages display | `resources/views/partials/flash.blade.php` |
| Add a ticket column | new migration + `$fillable` (if from form) + validation + `_form.blade.php` + `show.blade.php` |
| Demo data | `database/seeders/DatabaseSeeder.php` |

## 15. Debugging steps

1. Read the message → `storage/logs/laravel.log` (500s, `Log::` calls, emails).
2. `php artisan route:list --except-vendor -v` → name, method, middleware.
3. Status code meaning: **401** not logged in (JSON) · **403** `RoleMiddleware`/`TicketPolicy` · **404** missing route/model ·
   **405** wrong method · **419** CSRF/session · **422** JSON validation · **500** exception.
4. `dd($x)` / `dump($x)` / `Log::info('msg', [...])` at the suspected line (remove afterwards).
5. `php artisan tinker` → check data, `Gate::forUser($u)->allows('close', $t)`, `->toRawSql()`.
6. Browser F12 → Network (`/notifications/feed`, status, JSON) and Console (`Notification polling failed`).
7. Email missing → worker running? `SELECT * FROM jobs` → `queue:failed` → `failed_jobs.exception`.
8. Old data → `php artisan cache:clear` / `Cache::forget(...)`; `.env` change → `config:clear`; worker → `queue:restart`.
9. `LazyLoadingViolationException` → add the relationship to `with()`.
10. Field not saved → `$fillable`. FK error 1451 → row still referenced.
11. `php artisan test` after every fix.
