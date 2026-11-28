# 11 — Debugging Guide

How to find and fix problems in ResolveDesk. Every example uses the real files, routes and tables of this
project. Part 1 explains the **tools**. Part 2 explains the **common errors** with ResolveDesk examples.
Part 3 lists **real bugs that were found during development**. Part 4 (V2) covers 409 conflicts, 429 rate limits,
lock timeouts, Reverb/WebSocket problems, `/broadcasting/auth` 403, the scheduler, attachments and request-id log search.

Related: [System design](10-SYSTEM-DESIGN-GUIDE.md) · [Quick reference](12-QUICK-REFERENCE.md) ·
[Notifications](13-NOTIFICATION-SYSTEM-GUIDE.md) · [Queue and email](14-QUEUE-AND-EMAIL-GUIDE.md) ·
[Caching](15-CACHING-GUIDE.md) · [Setup](02-SETUP-GUIDE.md) · [Concurrency](20-CONCURRENCY-AND-DATA-INTEGRITY.md) ·
[Real-time](21-REALTIME-REVERB-ECHO.md) · [Security/logging](23-SECURITY-ERRORS-LOGGING-OPERATIONS.md)

---

## Before you start: use the right PHP

The PATH on this PC contains an older PHP. Every Artisan command in this guide assumes PHP 8.4.28:

```bash
# Git Bash
export PATH="/c/php-8.4.28:$PATH"
php -v          # must say 8.4.28
```

```powershell
# PowerShell
$env:Path = "C:\php-8.4.28;$env:Path"
php -v
```

Also make sure MariaDB is running (XAMPP Control Panel → MySQL → Start). If it is not, almost every page
fails with `SQLSTATE[HY000] [2002] No connection could be made because the target machine actively refused it`,
and `php artisan test` appears to **hang** (every test waits for the connection timeout).

## The 6-step debugging routine

1. **Read the exact error message** (browser page, Network tab response, or `storage/logs/laravel.log`).
2. **Find the layer**: route → middleware → controller → policy → validation → service → model/DB → view → JS.
3. **Reproduce** it with the smallest action (one URL, one button, one tinker line).
4. **Inspect data** (tinker or phpMyAdmin) — is the database what you expect?
5. **Add a temporary `dd()` / `Log::info()`** at the suspected line.
6. **Fix, remove the debug code, and run the tests**: `php artisan test`.

---

# Part 1 — Tools

## 1.1 Laravel logs

| Item | Value in ResolveDesk |
|---|---|
| File | `storage/logs/laravel.log` |
| Config | `.env`: `LOG_CHANNEL=stack`, `LOG_STACK=single`, `LOG_LEVEL=debug` |
| What is written | Uncaught exceptions (500 errors), your `Log::...()` calls, and **emails** (because `MAIL_MAILER=log`) |
| What is NOT written | 404, 403, 419 and validation errors — Laravel does not "report" these by default |

Read the end of the log:

```bash
tail -n 50 storage/logs/laravel.log          # Git Bash
```
```powershell
Get-Content storage\logs\laravel.log -Tail 50 # PowerShell
Get-Content storage\logs\laravel.log -Wait -Tail 20   # follow live
```

ResolveDesk's own log messages (search for these):

| Message | Written by | Meaning |
|---|---|---|
| `Could not queue ticket email` | `TicketNotifier::queueEmail()` | The job could not be pushed to the queue after commit. The ticket change is saved. |
| `Ticket email failed permanently` | `SendTicketEmail::failed()` | All 3 attempts failed; the job is now in `failed_jobs`. |

Tip: the log can get large. It is safe to empty it while developing (delete the content, not the folder).

## 1.2 `dd()` — dump and die

`dd($value)` prints the value and **stops** the request. Use it to see what really arrives.

```php
// app/Http/Controllers/TicketController.php, inside store(), temporarily:
$validated = $request->validate([...]);
dd($validated, $request->user()->role);
```

Blade has `@dd($stats)` too.

What can go wrong: `dd()` inside an AJAX endpoint (`NotificationController@feed`) returns HTML, not JSON.
`notifications.js` then fails at `response.json()` and only prints
`Notification polling failed: ...` in the Console. Look at the **Network tab → Response** to see the dump.
**Never commit `dd()`.**

## 1.3 `dump()`

Same as `dd()` but the request **continues**. Good for loops:

```php
foreach ($recipients as $recipient) {
    dump($recipient?->id);   // temporary, inside TicketService::addComment()
}
```

Blade: `@dump($ticket->status)`. Output appears at the top of the page.

## 1.4 `Log::info()` and friends

Use logging when you cannot see the screen (queue worker, AJAX, redirects).

```php
use Illuminate\Support\Facades\Log;

// Temporary line inside TicketAssignmentService::autoAssign(), after findLeastBusyAgent():
Log::info('Auto assign chose agent', ['ticket' => $lockedTicket->ticket_number, 'agent_id' => $agent?->id]);
```

Levels: `Log::debug()`, `Log::info()`, `Log::warning()`, `Log::error()`. The project already uses
`Log::error()` in `TicketService` and `SendTicketEmail`. Always pass context as an array.

## 1.5 Laravel Tinker

An interactive PHP shell with the whole app loaded.

```bash
php artisan tinker
```

Useful ResolveDesk lines (type one at a time):

```php
// Find data
$t = App\Models\Ticket::where('ticket_number', 'TK-000001')->first();
$t->status; $t->due_at; $t->assigned_to;
$t->canPerform('close');          // is "close" allowed in the current status?
$t->isOverdue();
App\Models\Ticket::overdue()->count();

// Users and roles
$sarah = App\Models\User::where('email', 'sarah.agent@resolvedesk.test')->first();
$sarah->isAgent(); $sarah->is_active;
$sarah->unreadNotifications()->count();
$sarah->notifications()->first()->data;

// Who would auto-assign pick right now? (read-only)
app(App\Services\TicketService::class)->findLeastBusyAgent()?->name;

// Policy check without the browser
Illuminate\Support\Facades\Gate::forUser($sarah)->allows('view', $t);

// See the SQL a query builds
App\Models\Ticket::visibleTo($sarah)->toRawSql();

// Count queries
DB::enableQueryLog(); App\Models\Ticket::with('creator')->take(5)->get(); count(DB::getQueryLog());

// Cache
Cache::get('dashboard:admin:stats');
Cache::forget('dashboard:admin:stats');
App\Models\Category::clearActiveCache();

// Queue
DB::table('jobs')->count();
DB::table('failed_jobs')->count();
```

Careful: tinker works on the **real dev database** (`resolvedesk`). Calls like `->save()`, `->delete()` or
`$service->resolve(...)` really change data. Re-seed only if you accept losing data:
`php artisan migrate:fresh --seed` **deletes everything** — ask first.

## 1.6 Browser developer tools (F12)

- **Elements**: check that a form has `<input type="hidden" name="_token">` (from `@csrf`) and, for edit forms,
  `<input type="hidden" name="_method" value="PUT">` (from `@method('PUT')`). Check `<meta name="csrf-token">`
  in `<head>` and the `data-feed-url` attributes on `#notificationBell`.
- **Application → Cookies**: the session cookie (named after `APP_NAME`, e.g. `resolvedesk-session`) and `XSRF-TOKEN`.
  Delete them to simulate an expired session.

## 1.7 Network tab

Most useful for AJAX notifications.

1. Open F12 → **Network** → filter `feed`.
2. A request to `/notifications/feed` should appear every 5 seconds (every 30 s if the tab is hidden).
3. Click a request:
   - **Headers**: status code, `Accept: application/json`, `X-Requested-With: XMLHttpRequest`.
   - **Payload/Query**: `since=2026-11-25T10:15:30+06:00`.
   - **Response**: the JSON (`unread_count`, `notifications`, `new_notifications`, `server_time`).
4. For "mark as read", filter `read` and check the request has the `X-CSRF-TOKEN` header and returns
   `{"success":true,"unread_count":...}`.

For normal form posts, enable **Preserve log**: a failed validation shows as `302` back to the form,
a CSRF problem as `419`, a policy denial as `403`.

## 1.8 Console tab

`public/js/notifications.js` writes warnings here instead of breaking the page:

| Console message | Meaning |
|---|---|
| `Notification polling failed: Server responded with 500` | The feed threw an exception → read `laravel.log` |
| `Notification polling failed: Unexpected token '<' ...` | The server returned HTML (a `dd()`, an error page, or a login redirect) instead of JSON |
| `Could not mark notification as read: ...` | POST to `notifications.read` failed |
| `Could not mark all notifications as read: ...` | POST to `notifications.read-all` failed |
| `bootstrap is not defined` | `vendor/bootstrap/bootstrap.bundle.min.js` did not load (check `public/vendor`) |

You can also type in the Console: `document.getElementById('notificationBell').dataset` to see the URLs.

## 1.9 `php artisan route:list`

```bash
php artisan route:list --except-vendor          # only our routes
php artisan route:list --name=tickets           # routes whose name contains "tickets"
php artisan route:list --path=notifications     # by URI
php artisan route:list --except-vendor -v       # also shows middleware (auth, active, role:admin)
```

Use it to answer: Does the route exist? What is its **name** (for `route('...')`)? Which **HTTP method**?
Which **middleware** protects it? Example: `tickets.assign` shows `role:admin`, `tickets.start` does not
(agents use it; the policy decides).

## 1.10 Database inspection

- **phpMyAdmin** (comes with XAMPP): `http://localhost/phpmyadmin` → database `resolvedesk`.
- **MySQL client**: `C:\xampp\mysql\bin\mysql.exe -u root resolvedesk`
- **Artisan**:
  ```bash
  php artisan db:show              # connection, tables, sizes
  php artisan db:table tickets     # columns, indexes, foreign keys of one table
  php artisan migrate:status       # which migrations ran
  ```

Handy SQL:

```sql
SELECT id, ticket_number, status, priority, assigned_to, due_at, updated_at FROM tickets ORDER BY id DESC LIMIT 10;
SELECT action, old_value, new_value, created_at FROM ticket_activities WHERE ticket_id = 1 ORDER BY id;
SELECT id, notifiable_id, read_at, data, created_at FROM notifications ORDER BY created_at DESC LIMIT 10;
SELECT `key`, expiration FROM cache;
SELECT id, queue, attempts, reserved_at, available_at FROM jobs;
SELECT uuid, failed_at, LEFT(exception, 200) FROM failed_jobs;
SHOW CREATE TABLE tickets;   -- check due_at is DATETIME (see bug 3.1)
```

Tests use a separate database `resolvedesk_testing` (set in `phpunit.xml`), so running tests never touches your dev data.

## 1.11 VS Code and Xdebug

- VS Code is used for this project (`.vscode/settings.json` exists).
- **Xdebug is not installed** in `C:\php-8.4.28` at the time of writing (`php -m` does not list it). Until it is,
  use `dd()`, `dump()`, `Log::info()` and tinker.
- If you install it later: add the matching Xdebug DLL to `C:\php-8.4.28\ext`, enable it in
  `C:\php-8.4.28\php.ini` (`zend_extension=xdebug`, `xdebug.mode=debug`, `xdebug.start_with_request=yes`),
  install the "PHP Debug" VS Code extension, create a "Listen for Xdebug" launch configuration,
  then set a breakpoint in e.g. `TicketAssignmentService::performAssignment()` and click **Assign** in the browser.
  This is optional and not part of the verified setup.

## 1.12 Queue worker output

```bash
php artisan queue:work                     # keep running, process jobs as they arrive
php artisan queue:work --stop-when-empty   # process waiting jobs, then exit (used during verification)
php artisan queue:work -v                  # more detail
```

Each job prints a line with its class (`App\Jobs\SendTicketEmail`) and a result such as **DONE** or **FAIL**.
A FAIL followed by another attempt about 10 seconds later is the `$backoff = 10` retry. After 3 attempts the
job is moved to `failed_jobs`.

Important: the worker is a **long-running process**. It loads your code once. After changing
`SendTicketEmail`, `TicketUpdateMail` or `resources/views/emails/ticket-update.blade.php`, restart it
(Ctrl+C and start again, or `php artisan queue:restart`).

## 1.13 Failed job records

```bash
php artisan queue:failed                 # list: uuid, connection, queue, class, failed at
php artisan queue:retry <uuid>           # push one failed job back to the queue
php artisan queue:retry all              # retry all failed jobs
php artisan queue:forget <uuid>          # delete one failed job record
php artisan queue:flush                  # delete ALL failed job records
```

The `failed_jobs.exception` column holds the full stack trace — that is where you see, for example,
`Connection could not be established with host "smtp.gmail.com"` or an authentication error. Fix the cause
first (e.g. `.env` mail settings + `php artisan config:clear`), then `queue:retry`.

## 1.14 Cache inspection

```bash
php artisan cache:clear        # empties the whole cache table
```

```php
// tinker
Cache::has('dashboard:admin:stats');
Cache::get('ticket:active-categories');
Cache::forget('ticket:active-categories');
```

In the database the key is stored **with a prefix**: `config/cache.php` uses
`Str::slug(APP_NAME) . '-cache-'`, so the row is `resolvedesk-cache-dashboard:admin:stats`.
The admin dashboard also shows `generated_at` from the cached array, so you can see when the statistics were
last rebuilt.

Do not confuse with other caches:

| Command | Clears |
|---|---|
| `php artisan cache:clear` | application cache (`cache` table) |
| `php artisan config:clear` | cached config (needed after editing `.env` if you ran `config:cache`) |
| `php artisan route:clear` | cached routes |
| `php artisan view:clear` | compiled Blade files in `storage/framework/views` |
| `php artisan optimize:clear` | all of the above |

> **বাংলায়:** Debugging-এর মূল কথা: আন্দাজ না করে প্রমাণ দেখা। Error message পড়ুন, `laravel.log` দেখুন,
> tinker দিয়ে database-এর আসল data দেখুন, আর browser-এর Network tab-এ request/response দেখুন। `dd()` request
> থামিয়ে value দেখায়, `Log::info()` file-এ লেখে — queue worker বা AJAX-এর মতো জায়গায় যেখানে screen দেখা যায় না,
> সেখানে log সবচেয়ে কাজের।

---

# Part 2 — Common errors with ResolveDesk examples

Quick map:

| Symptom | Likely cause | Section |
|---|---|---|
| `Route [x] not defined` | wrong route name | 2.1 |
| `View [x] not found` | wrong view name / path | 2.2 |
| `Undefined variable $x` | controller did not pass it | 2.3 |
| Field silently not saved / `MassAssignmentException` | `$fillable` | 2.4 |
| 404 on `/tickets/999` | route model binding | 2.5 |
| `SQLSTATE[...]` | SQL error | 2.6 |
| `1451 Cannot delete or update a parent row` | foreign key | 2.7 |
| Form returns with red messages | validation | 2.8 |
| 401 / 403 / 404 / 419 / 422 / 500 | HTTP errors | 2.9–2.14 |
| Bell never updates | AJAX failure | 2.15 |
| Email never sent | queue / mail | 2.16, 2.17 |
| Old numbers on dashboard | stale cache | 2.18 |
| `LazyLoadingViolationException` | missing `with()` | 2.19 |
| `__PHP_Incomplete_Class` / `TypeError` from cache | object cached, `serializable_classes => false` | 2.20 |

## 2.1 Route not found

**Message:** `Symfony\Component\Routing\Exception\RouteNotFoundException: Route [tickets.destroy] not defined.`

**ResolveDesk example:** there is **no** `tickets.destroy` route (`Route::resource('tickets', ...)->except(['destroy'])`)
and no `categories.show` (`->except(['show'])`) and no `users.destroy`. Writing `route('tickets.destroy', $ticket)`
in a view causes this error.

**Related:** calling a URL with the wrong method, e.g. opening `/tickets/1/start` in the address bar (GET) when
only POST exists → **405 Method Not Allowed**. Visiting a URL that does not exist at all → **404**.

**Debug:** `php artisan route:list --except-vendor` and copy the exact name. If you just added a route and it is
missing, run `php artisan route:clear`.

## 2.2 View not found

**Message:** `InvalidArgumentException: View [ticket.show] not found.`

Dot notation maps to folders: `view('tickets.show')` → `resources/views/tickets/show.blade.php`.
`ticket.show` (singular) does not exist. Same for `@include('partials.flash')` → `resources/views/partials/flash.blade.php`
and `@extends('layouts.app')`.

Email view: `TicketUpdateMail::content()` uses `view: 'emails.ticket-update'` → `resources/views/emails/ticket-update.blade.php`.
A typo here fails **inside the queue worker**, not in the browser → check worker output / `failed_jobs`.

## 2.3 Undefined variable

**Message:** `ErrorException: Undefined variable $agents` (shown as a 500 page with the Blade file and line).

**ResolveDesk example:** `resources/views/tickets/show.blade.php` loops over `$agents` for the assign dropdown.
`TicketController@show` passes it:

```php
return view('tickets.show', [
    'ticket' => $ticket,
    'agents' => $user->isAdmin() ? $this->assignmentService->eligibleAgents($ticket) : collect(),
]);
```

If you remove the `'agents'` key, the page breaks for admins. Note it passes an **empty collection** for
non-admins instead of nothing, so the variable always exists.

Partials use defaults for optional variables, e.g. `@php($only = $only ?? null)` in
`resources/views/partials/ticket-badges.blade.php`.

## 2.4 MassAssignmentException vs silently ignored attributes

Laravel protects models from saving unexpected form fields. Each ResolveDesk model has a `$fillable` list.

**Case A — the field is silently ignored (most common in this project).**
`Ticket::$fillable` is only `subject`, `description`, `category_id`, `priority`. `status` is **not** fillable on
purpose, so a hacked form cannot change the status:

```php
$ticket->update(['status' => 'closed']);   // no error, but status is NOT changed
$ticket->fill(['priority' => 'high', 'status' => 'closed']); // only priority is filled
```

The service sets protected columns explicitly: `$lockedTicket->status = Ticket::STATUS_RESOLVED;`.

Symptom of the same mechanism that turns into an SQL error:

```php
App\Models\TicketComment::create(['message' => 'Hi', 'ticket_id' => 1, 'user_id' => 1]);
// ticket_id and user_id are NOT fillable -> dropped ->
// SQLSTATE[HY000]: General error: 1364 Field 'ticket_id' doesn't have a default value
```

That is why `TicketService::addComment()` sets `$comment->ticket_id` and `$comment->user_id` one by one.

**Case B — a real `MassAssignmentException`.**
Thrown when a model is **totally guarded** (no `$fillable` at all and the default `$guarded = ['*']`) and you call
`create()`/`fill()`/`update()` with an array. Example: if someone deleted the `$fillable` property from
`app/Models/Category.php`, then `Category::create($validated)` in `CategoryController@store` would throw
`Add [name] to fillable property to allow mass assignment on [App\Models\Category].`

**Fix:** add the column to `$fillable` **only if users may legitimately set it from a form**. Otherwise set it
explicitly in code.

> **বাংলায়:** Mass assignment মানে পুরো array একবারে model-এ বসানো (`create($data)`)। `$fillable`-এ যে column নেই,
> Laravel সেটা চুপচাপ বাদ দেয় — তাই `status` form থেকে বদলানো যায় না। কোনো `$fillable` না থাকলে Laravel সরাসরি
> `MassAssignmentException` দেয়। গুরুত্বপূর্ণ column (status, created_by) সবসময় code-এ আলাদা করে set করা হয়।

## 2.5 ModelNotFoundException (route model binding → 404)

`public function show(Request $request, Ticket $ticket)` — Laravel runs `Ticket::findOrFail($id)` for the
`{ticket}` segment. `/tickets/99999` → `ModelNotFoundException` → Laravel converts it to **404 Not Found**.

Other places that throw it on purpose:
- `NotificationController@markAsRead`: `$request->user()->notifications()->findOrFail($id)` → another user's
  notification id gives 404 (`test_user_cannot_read_someone_elses_notification`).
- `TicketController@assign`: `User::findOrFail($validated['agent_id'])`.
- `TicketService::lockTicket()`: `->firstOrFail()`.

In tinker you see the raw exception: `App\Models\Ticket::findOrFail(99999)` →
`No query results for model [App\Models\Ticket] 99999`.

## 2.6 SQL errors

All appear as `Illuminate\Database\QueryException` with `SQLSTATE[...]` and the SQL. Read the SQL in the message.

| Message | ResolveDesk cause |
|---|---|
| `[2002] ... actively refused it` | MariaDB not started in XAMPP |
| `[1049] Unknown database 'resolvedesk_testing'` | test DB not created (needed for `php artisan test`) |
| `[42S02] Base table or view not found: 1146 Table 'resolvedesk.jobs' doesn't exist` | migrations not run → `php artisan migrate` |
| `[42S22] Column not found: 1054 Unknown column 'assigned_agent'` | used the relationship name instead of the column `assigned_to` in `where()` |
| `[HY000] 1364 Field 'ticket_id' doesn't have a default value` | non-fillable attribute dropped (2.4) |
| `[23000] 1062 Duplicate entry 'Billing' for key 'categories_name_unique'` | unique index — validation `unique:categories,name` should catch it first |
| `[23000] 1451 Cannot delete or update a parent row` | foreign key (2.7) |

## 2.7 Foreign key violations

**ResolveDesk example — deleting a category in use.** `tickets.category_id` uses `restrictOnDelete()`.
`CategoryController@destroy` checks first, so the UI shows a friendly message:

```php
if ($category->tickets()->exists()) {
    return back()->with('error', 'This category is used by existing tickets and cannot be deleted. Deactivate it instead.');
}
```

If you bypass that check (or delete in tinker), the database itself refuses:

```php
App\Models\Category::find(1)->delete();
// SQLSTATE[23000]: Integrity constraint violation: 1451 Cannot delete or update a parent row:
// a foreign key constraint fails (... FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`))
```

Same for users: `created_by`, `assigned_to`, `ticket_comments.user_id`, `ticket_activities.user_id` all restrict
deletes. That is why there is no `users.destroy` route — users are deactivated (`users.toggle-active`).

**Debug:** `php artisan db:table tickets` shows the foreign keys. Find what still references the row:
`SELECT id FROM tickets WHERE category_id = 1;`

## 2.8 Validation errors

On a normal form, failed `$request->validate()` → **302 redirect back**, old input kept, errors in `$errors`.
`resources/views/partials/flash.blade.php` lists them under "Please fix the following:" and fields use
`@error('subject') is-invalid @enderror`.

Business-rule failures in `TicketService` use the same mechanism:

```php
throw ValidationException::withMessages([
    'ticket' => "This action is not allowed while the ticket is {$ticket->statusLabel()}.",
]);
```

Examples you can trigger: auto-assign a ticket that is not Open ("Auto assign is only available for Open
tickets. Use Reassign instead."), reopen when the agent is inactive, choose an inactive category
("Please choose an active category."), deactivate an agent who still has tickets (`UserController`).

**Debug:** Network tab shows 302; the session carries the errors. In tests: `->assertSessionHasErrors('category_id')`.
If an error message does not show, check the field name in `@error('...')` matches the validation key.

## 2.9 HTTP 401 — Unauthenticated

Only for JSON requests. A normal browser request without login is **redirected** to `/login`
(`$middleware->redirectGuestsTo('/login')` in `bootstrap/app.php`).

ResolveDesk examples:
- Polling `/notifications/feed` after the session expired → `{"message":"Unauthenticated."}` with 401
  (`test_feed_requires_authentication`).
- A user deactivated by an admin while logged in: `ActiveUserMiddleware` logs them out and returns
  `response()->json(['message' => $message], 401)` for AJAX.

`notifications.js` handles both: `if (response.status === 401 || response.status === 419) { handleExpiredSession(); }`
→ polling stops and the dropdown says "Your session has expired. Sign in again".

## 2.10 HTTP 403 — Forbidden

Two sources, both rendered with `resources/views/errors/403.blade.php` (shows `$exception->getMessage()`):

| Source | Example | Message on the page |
|---|---|---|
| `RoleMiddleware` | customer opens `/users` or `/categories` | "You do not have permission to access this page." |
| `TicketPolicy` via `Gate::authorize()` | customer `john` opens another customer's `/tickets/{id}`; agent POSTs `/tickets/{id}/close`; anyone POSTs a comment to a Closed ticket (`comment()` returns false) | "This action is unauthorized." |

**Debug:**
1. Which middleware? `php artisan route:list -v --name=tickets.close`.
2. Which policy method? The controller line `Gate::authorize('close', $ticket)`.
3. Test the rule in tinker: `Gate::forUser($user)->allows('close', $ticket)`; check `$ticket->status`,
   `$ticket->created_by`, `$ticket->assigned_to`, `$user->role`.
4. Remember: many policy methods also check the status (`canPerform()`), so a correct user still gets 403 when
   the ticket is in the wrong status.

## 2.11 HTTP 404 — Not Found

- Unknown URL (typo, e.g. `/ticket/1`).
- Route model binding with a missing id (2.5).
- `markAsRead` with someone else's notification id.
- Not logged in `laravel.log` (404 is not reported).

## 2.12 HTTP 419 — Page Expired (CSRF)

Every POST/PUT/PATCH/DELETE must carry the session's CSRF token. ResolveDesk shows
`resources/views/errors/419.blade.php` ("Page expired ... Please go back, refresh the page and try again.").

Causes in this project:
1. **Missing `@csrf`** in a new form. Every existing form has it, e.g. `resources/views/tickets/show.blade.php`
   workflow buttons. Verified during smoke testing: a POST without the token returned 419.
2. **Expired session**: `SESSION_LIFETIME=120` minutes. A form left open longer fails.
3. **Logged out in another tab**: `AuthController@logout` calls `$request->session()->regenerateToken()`, so old
   forms in other tabs carry an old token.
4. **AJAX without the header**: `notifications.js` sends `'X-CSRF-TOKEN': csrfToken` read from
   `<meta name="csrf-token" content="{{ csrf_token() }}">` in `layouts/app.blade.php`. Remove that meta tag and
   "mark as read" breaks with 419.
5. Session cookie blocked/deleted, or `sessions` table missing.

**Debug:** Elements tab → is `_token` in the form? Network → is the cookie sent? Do not "fix" 419 by disabling CSRF.

> **বাংলায়:** CSRF token হলো একটা গোপন random মান, যেটা প্রতিটা form-এ (`@csrf`) আর AJAX header-এ
> (`X-CSRF-TOKEN`) পাঠাতে হয়। এতে অন্য website আপনার নামে লুকিয়ে POST request পাঠাতে পারে না। Token না থাকলে বা
> session-এর মেয়াদ শেষ হলে Laravel 419 "Page expired" দেখায় — page refresh করলে নতুন token পাওয়া যায়।

## 2.13 HTTP 422 — Unprocessable Content

Validation failure on a **JSON** request (instead of the 302 redirect).

**ResolveDesk example:** `NotificationController@feed` validates `'since' => ['nullable', 'date']`.

```text
GET /notifications/feed?since=abc   (with Accept: application/json)
→ 422 {"message":"The since field must be a valid date.","errors":{"since":["The since field must be a valid date."]}}
```

Without that rule, `Carbon::parse('abc')` would throw and return a 500. Business-rule `ValidationException`s from
`TicketService` would also become 422 if called with `Accept: application/json`.

**Debug:** Network tab → Response shows the `errors` object with the failing field.

## 2.14 HTTP 500 — Server Error

Any uncaught exception. With `APP_DEBUG=true` (local) Laravel shows the exception page with file and line;
with `APP_DEBUG=false` users see a generic error page. **Always read `storage/logs/laravel.log`** — the full
stack trace is there. Never set `APP_DEBUG=true` in production (it leaks code and config).

Typical ResolveDesk 500s: undefined variable in Blade (2.3), `LazyLoadingViolationException` (2.19), SQL error (2.6),
Blade compile error (3.2), DB not running.

## 2.15 AJAX request failure (notification bell)

Checklist when the bell/badge never updates or toasts never appear:

1. **Console**: any `Notification polling failed:` message? Any JS error before it (e.g. `bootstrap is not defined`)?
2. **Network**: is `/notifications/feed` requested every 5 s? If not, is `#notificationBell` on the page?
   (`notifications.js` returns early with `if (!bell) { return; }` — e.g. on the login page.)
3. **Status code**: 401/419 → session (2.9, 2.12); 422 → bad `since` (2.13); 500 → `laravel.log`.
4. **Response**: is it JSON? HTML means a `dd()`, an error page or a redirect to `/login`.
5. **Data**: does the user actually have rows? `$user->unreadNotifications()->count()` in tinker. Remember the
   actor is never notified about their own action (`TicketNotifier::notify()` skips `$recipient->id === $actor->id`) and
   inactive recipients are skipped.
6. **Toast but no new toast?** Toasts only show for `new_notifications` after the first poll, and an id is shown
   once (`shownToastIds`). Reloading the page resets this (first load never toasts).

Note: the browser toast itself was **not verified** in a real browser during development (see
`DEVELOPMENT_PROGRESS.md`); the JSON feed was verified with curl and tests.

## 2.16 Queue job not processing

Symptom: assignment works, in-app notification appears, but no email in `laravel.log`.

1. **Is a worker running?** With `QUEUE_CONNECTION=database`, jobs wait in `jobs` until `php artisan queue:work`
   runs. Check: `SELECT COUNT(*) FROM jobs;` — rows waiting = no worker. (This is expected and was verified:
   2 jobs waited until `queue:work --stop-when-empty` processed them.)
2. **Is the right connection used?** `php artisan config:show queue.default` (or tinker `config('queue.default')`).
   If you ran `php artisan config:cache` earlier, `.env` changes are ignored → `php artisan config:clear`.
3. **Job in backoff?** `jobs.available_at` is in the future for ~10 s after a failed attempt.
4. **Job reserved by a crashed worker?** `reserved_at` is set; it becomes available again after `retry_after`
   (90 s, `config/queue.php`).
5. **Worker running old code?** `php artisan queue:restart`.
6. **No row at all?** Then dispatch never happened: was the transaction rolled back (no job by design,
   `DB::afterCommit()`), is this an event that does not email (comment, close), is the recipient inactive or
   the actor themselves? Search `laravel.log` for `Could not queue ticket email`.

Tests use `QUEUE_CONNECTION=sync` (`phpunit.xml`), so jobs run immediately there — a test passing does not prove
your worker is running.

> **বাংলায়:** Queue মানে কাজ পরে করার জন্য লাইনে রাখা। Web request শুধু `jobs` table-এ একটা row রাখে; আলাদা
> `queue:work` process সেটা তুলে email পাঠায়। Worker না চললে job শুধু অপেক্ষা করে — হারায় না। Code বদলালে worker
> restart করতে হয়, কারণ সে পুরনো code memory-তে রেখে চলে।

## 2.17 Email delivery failure

1. With `MAIL_MAILER=log` (default) emails are **not sent** — they are written to `storage/logs/laravel.log`.
   Search the log for the subject, e.g. `[TK-000005] New ticket assigned`.
2. With SMTP (e.g. Gmail), failures happen in the worker: the job is retried 3 times (10 s apart), then
   `SendTicketEmail::failed()` logs `Ticket email failed permanently` and the job goes to `failed_jobs`.
   Read `failed_jobs.exception` (wrong password / app password, port, `MAIL_SCHEME`, firewall).
3. After fixing `.env`: `php artisan config:clear`, restart the worker, `php artisan queue:retry all`.
4. The ticket change is never undone by a mail failure (`test_email_failure_does_not_reverse_the_committed_assignment`).
5. Real Gmail SMTP delivery was **not verified** during development (no credentials).

## 2.18 Stale cache

Symptom: the admin dashboard shows old numbers, or the category dropdown still shows a deactivated category
(or misses a new one).

Why it can happen even though the app clears caches:
- Data was changed **outside** the app's write paths (phpMyAdmin, raw SQL, tinker `DB::table(...)->update(...)`).
  Only `TicketService` and `CategoryController` clear the keys.
- TTLs: dashboard 60 s (`DashboardController::ADMIN_STATS_TTL_SECONDS`), categories 3600 s
  (`Category::ACTIVE_CACHE_TTL_SECONDS`).

Fix:

```bash
php artisan cache:clear
```
```php
// tinker — clear only one key
Cache::forget('dashboard:admin:stats');
App\Models\Category::clearActiveCache();   // forgets 'ticket:active-categories'
```

Why stale categories are harmless for data: `TicketController@store` validates with
`Rule::exists('categories', 'id')->where('is_active', true)` against the database, so an inactive category from a
stale dropdown is rejected ("Please choose an active category.").

Not to be confused with: stale **config** (`config:clear`), stale **compiled views** (`view:clear`), stale
**worker code** (`queue:restart`), or the browser's own cache (Ctrl+F5 for `public/js/*.js`).

## 2.19 LazyLoadingViolationException (preventLazyLoading)

`AppServiceProvider::boot()` has `Model::preventLazyLoading(! $this->app->isProduction());`.

**Reproduce:** in `TicketController@index` change
`Ticket::with(['category', 'creator', 'assignedAgent'])` to `Ticket::with(['category', 'assignedAgent'])`
and open `/tickets`. The view calls `$ticket->creator->name` →

```text
Illuminate\Database\LazyLoadingViolationException
Attempted to lazy load [creator] on model [App\Models\Ticket] but lazy loading is disabled.
```

**Fix:** add the relationship to `with()` (lists) or `load()` (single model, e.g. `TicketController@show`).

Notes:
- Laravel only throws this when the model was loaded **as part of a collection with more than one model**.
  A single model (e.g. the locked ticket in `TicketService`, where `$lockedTicket->creator` is used) may lazy load —
  no N+1 risk there.
- In production the check is off, so a missed case is just slower, not broken.
- `tests/Feature/PageRenderingTest.php` seeds demo data and opens every page for every role to catch these.

## 2.20 `__PHP_Incomplete_Class` / TypeError from cache

**Symptoms:** a page works on the first request but returns 500 on the next one (a cache hit). The log shows e.g.

```text
TypeError: App\Models\Category::activeForDropdown(): Return value must be of type
Illuminate\Database\Eloquent\Collection, __PHP_Incomplete_Class returned
```

**Cause:** an **object** (Eloquent model or `Collection`) was put in the cache. Laravel 13's `config/cache.php` has
`'serializable_classes' => false`, so the `database` store unserializes with `allowed_classes => false` (protection
against PHP object injection) and every object comes back as `__PHP_Incomplete_Class`. Tests with
`CACHE_STORE=array` (our `phpunit.xml`) do not serialize, so they do not show it.

**Fix:** cache a plain array and rebuild models after reading (this is what `Category::activeForDropdown()` does now):

```php
$rows = Cache::remember(self::ACTIVE_CACHE_KEY, self::ACTIVE_CACHE_TTL_SECONDS, function () {
    return Category::where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray();
});

return Category::hydrate($rows);
```

Then remove the broken cached rows:

```bash
php artisan cache:clear
```

Regression test: `CacheTest::test_category_cache_works_with_the_real_database_cache_store` (switches to the real
`database` store). Details: [Caching guide §5.1](15-CACHING-GUIDE.md).

---

# Part 3 — Real bugs found during development

These come from `DEVELOPMENT_PROGRESS.md` → "Bugs found and fixed during verification". They are great interview
stories: symptom → investigation → root cause → fix.

## 3.1 MariaDB TIMESTAMP auto-update overwrote the SLA deadline

- **Symptom:** found while seeding: a ticket's `due_at` (SLA deadline) was replaced with the current time on
  every UPDATE of the row, so SLA and overdue results were wrong. (Any update triggers it — even saving the
  ticket number right after the insert in `createTicket()`.)
- **Root cause:** on MariaDB (and older MySQL without `explicit_defaults_for_timestamp`), the **first NOT NULL
  `TIMESTAMP` column** of a table silently gets `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. `due_at` was
  that column, so **every UPDATE overwrote it**.
- **How to see it:** `SHOW CREATE TABLE tickets;` — look for `ON UPDATE current_timestamp()` on a column you did not expect.
- **Fix:** the migration uses `DATETIME` instead:
  ```php
  $table->dateTime('due_at');                  // SLA deadline = created_at + priority hours
  $table->dateTime('resolved_at')->nullable(); // Set when resolved, cleared when reopened
  ```
- **Lesson:** test on the real database engine; read the generated schema, not only the migration.

> **বাংলায়:** MariaDB-তে table-এর প্রথম NOT NULL TIMESTAMP column-এ নিজে থেকেই "update হলে এখনকার সময় বসাও"
> নিয়ম যোগ হয়ে যায়। তাই ticket-এ যেকোনো update হলেই `due_at` বদলে যাচ্ছিল। Column-কে DATETIME করায় এই লুকানো
> নিয়মটা আর প্রযোজ্য নয়।

## 3.2 Blade `@json` inside a JavaScript comment

- **Symptom:** the admin dashboard (`resources/views/dashboard/admin.blade.php`) failed to render.
- **Root cause:** a JavaScript comment mentioned the `@json` directive. Blade does not understand JavaScript —
  it compiles directives **everywhere** in the file, including inside `// comments` and HTML comments. The text in the
  comment was compiled into PHP and broke the compiled view.
- **Fix:** write `@@json` in the comment. `@@` prints a literal `@` and is not compiled:
  ```blade
  // Blade's @@json directive safely converts the PHP arrays from DashboardController into JavaScript objects.
  // (Inside Blade, "@@" prints a literal "@" so this comment is not compiled as a directive.)
  const trendData = @json($stats['trend']);
  ```
- **Debug tip:** compiled views are in `storage/framework/views/*.php`; the error page points to the compiled file
  and the original Blade file. `php artisan view:clear` removes compiled files.

## 3.3 Multi-line `@include([...])` with a ternary did not compile

- **Symptom:** a Blade compile error on a stat card include.
- **Fix:** precompute the value with `@php` and pass a simple variable
  (`resources/views/dashboard/admin.blade.php`):
  ```blade
  @php($averageResolution = $stats['average_resolution_hours'] === null ? '—' : $stats['average_resolution_hours'] . ' h')
  ```
- **Lesson:** keep Blade directive arguments short and on one line; do logic in the controller or a `@php` line.

## 3.4 Category dropdown cache broke on the second request

- **Symptom:** `/tickets/create` and `/tickets/{id}/edit` worked once, then returned 500 for up to an hour.
- **Root cause:** `Category::activeForDropdown()` cached an Eloquent `Collection`; with `CACHE_STORE=database` and
  `'serializable_classes' => false` it came back as `__PHP_Incomplete_Class` (see 2.20). Tests use the `array`
  store, so they passed.
- **Fix:** cache `->get(['id', 'name'])->toArray()` and return `Category::hydrate($rows)`; added
  `test_category_cache_works_with_the_real_database_cache_store`.
- **Lesson:** test with the same cache store as the real application when serialization matters.

# Part 4 — V2 debugging (concurrency, real-time, scheduler, attachments, operations)

V2 added new failure modes. Each section: **symptom → why → how to check → fix**. Related guides:
[20 concurrency](20-CONCURRENCY-AND-DATA-INTEGRITY.md), [21 real-time](21-REALTIME-REVERB-ECHO.md),
[23 security/errors/logging](23-SECURITY-ERRORS-LOGGING-OPERATIONS.md), [18 deployment](18-PRODUCTION-DEPLOYMENT.md).

New log messages to search for:

| Message | Written by | Meaning |
|---|---|---|
| `Real-time broadcast failed; polling fallback will deliver the notification` | `TicketNotifier::broadcast()` (warning) | Reverb unreachable or misconfigured; the operation and the notification row are fine |
| `Ticket email already sent; skipping duplicate attempt` | `SendTicketEmail::handle()` (info) | A retried job found its `ticket-email-sent:{id}` key |
| `Attachment file missing on disk` | `TicketAttachmentController::download()` (error) | DB row exists but the file is gone → 404 |
| `Could not delete orphaned attachment file` | `AttachmentService::deleteFiles()` (warning) | Cleanup after a failed transaction could not delete a file |
| `SLA check finished: N breach notification(s) sent, M failure(s).` | `CheckSlaBreaches` (console output) | Result of one scheduler run |

## 4.1 Use the request id (`X-Request-Id`) to find log lines

`AddRequestContext` (appended to the `web` middleware group in `bootstrap/app.php`) gives every request an id,
returns it in the `X-Request-Id` response header and adds `request_id` + `user_id` to every log line of that request:

```text
[2026-11-26 14:03:11] local.WARNING: Real-time broadcast failed; polling fallback will deliver the notification
{"user_id":7,"error":"..."} {"request_id":"9b2f6c1e-...","user_id":1}
```

How to use it:

1. Browser F12 → Network → click the failing request → Response Headers → copy `X-Request-Id`.
2. Search the log:
   ```bash
   grep 9b2f6c1e storage/logs/laravel.log                       # Git Bash
   ```
   ```powershell
   Select-String -Path storage\logs\laravel.log -Pattern 9b2f6c1e # PowerShell
   ```
3. A reverse proxy may send its own `X-Request-Id`; it is reused only if it matches `^[A-Za-z0-9\-]{8,64}$`.

Only ids are logged by this middleware — never passwords, cookies or ticket text. Queue worker and scheduler log
lines do **not** carry the web request id (they run in other processes).

## 4.2 HTTP 409 Conflict / "This ticket was changed by someone else…"

**Why:** optimistic locking. The form sent `version = 4` but the row is already at `version = 5` because another
user (or the SLA scheduler, or an assignment) changed the ticket after the page was opened.
`TicketService::updateTicket()` runs `UPDATE ... WHERE id = ? AND version = ? AND status <> 'closed'` → 0 rows →
`TicketConflictException::staleVersion()`. Transitions and assignments call `Ticket::ensureVersion()` on the locked row.

**What the user sees:** HTML form → redirect back **with their input** and a red flash message (no 409 page exists;
`resources/views/errors/` has 403, 419 and 429). JSON / `Accept: application/json` → HTTP **409** `{"message": "..."}`.

**Check:**
```sql
SELECT id, ticket_number, version, status, updated_at FROM tickets WHERE ticket_number = 'TK-000035';
SELECT action, old_value, new_value, created_at, user_id FROM ticket_activities WHERE ticket_id = 35 ORDER BY id DESC LIMIT 5;
```
The latest activity tells you who changed it (`user_id NULL` = system, e.g. `sla_breached`).

**Other 409 cause:** the same `request_token` re-submitted with **different** content (`replayOrReject()`): the user
edited an old, already-submitted form. Fix: open a new create form (new token).

**Fix for the user:** reload, review, submit again. **Developer mistakes:** forgetting the hidden
`<input type="hidden" name="version" ...>` in a new form (then no version check happens for transitions — `version`
is `nullable` there), or sending the version of a stale model.

> **বাংলায়:** 409 মানে "তুমি যে তথ্য দেখে সিদ্ধান্ত নিয়েছ, সেটা এখন আর সর্বশেষ নয়"। পেজ রিলোড করে নতুন অবস্থা দেখে
> আবার চেষ্টা করতে হবে। এটা বাগ নয় — অন্যের পরিবর্তন চুপচাপ মুছে ফেলা ঠেকানোর জন্যই এটা ইচ্ছাকৃত।

## 4.3 HTTP 429 Too Many Requests

**Why:** a named rate limiter in `AppServiceProvider::configureRateLimiting()`:
`ticket-create` 10/min, `comments` 30/min, `notifications` 120/min, `exports` 5/min (per user id, or IP for guests);
`/health/ready` 60/min per IP; login 5 failures per email + IP (shown as a validation error, not 429).

**Check:** `php artisan route:list --except-vendor -v` shows `throttle:...` on the route. Response headers
`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After`.

**Typical causes:** an automated test script, many browser tabs polling while the socket is unhealthy
(each tab polls every 5 s = 12/min), a JS loop calling the feed repeatedly.

**Fix:** wait a minute. Locally only: `php artisan cache:clear` resets the counters (they live in the cache store).
Do not raise limits to hide a JS bug.

## 4.4 Assignment lock timeout: "Another assignment is being processed right now"

**Why:** `TicketAssignmentService::withAssignmentLock()` waited `assignment_lock.wait_seconds` (5 s) for
`Cache::lock('ticket-assignment')` and got `LockTimeoutException`, converted to a validation error (redirect back),
**not** a 409.

**Check:**
```sql
SELECT `key`, owner, FROM_UNIXTIME(expiration) FROM cache_locks;
```
- A row with a future expiration → an assignment is running (or a slow one is stuck — Reverb hanging is no longer a
  cause, because broadcasts are held until after the lock).
- A row with a past expiration is harmless; the next acquire takes it over.
- Tests use `CACHE_STORE=array`, so `cache_locks` is not touched there.

**Fix:** retry. If it happens often: look for slow queries inside the locked section (`findLeastBusyAgent()`), check
DB lock waits (`SHOW ENGINE INNODB STATUS`), and keep network calls outside the lock.

**Database lock wait** (different problem): `SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded` means a
row lock (`FOR UPDATE`) was held longer than `innodb_lock_wait_timeout` (50 s default) — usually a forgotten open
transaction in tinker. Deadlocks (`1213`) are retried automatically 3 times by `DB::transaction(..., 3)`.

## 4.5 Real-time toast does not appear (Reverb / WebSocket)

Work through the chain from left to right:

```text
TicketNotifier (after commit) -> Reverb :8080 -> browser WebSocket -> Echo channel users.{id}
   -> event .notification.created -> 'resolvedesk:realtime-notification' -> notifications.js -> GET /notifications/feed -> toast
```

| Check | How | If wrong |
|---|---|---|
| Is the notification in the DB? | `SELECT id, created_at FROM notifications WHERE notifiable_id = 7 ORDER BY created_at DESC LIMIT 3;` | Not a real-time problem — recipient rules (actor/inactive skipped) |
| Is Reverb running? | Terminal shows `php artisan reverb:start`; use `--debug` to print connections and messages | Start it; check port 8080 is free (`netstat -ano \| findstr 8080`) |
| Does Laravel reach Reverb? | Log line `Real-time broadcast failed…` | `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID/KEY/SECRET`, `REVERB_HOST/PORT/SCHEME`; `php artisan config:clear`; restart Reverb after `.env` changes |
| Is the bundle loaded? | View source: a `/build/assets/realtime-*.js` script; `public/build/manifest.json` exists | `npm run build` (the layout skips `@vite` when there is no manifest) |
| Correct browser settings? | Bundle built with the right `VITE_REVERB_APP_KEY/HOST/PORT/SCHEME` | Fix `.env`, **rebuild** (`VITE_*` values are baked in at build time) |
| WebSocket connected? | F12 → Network → **WS** filter → `ws://localhost:8080/app/<key>` → Messages tab shows `pusher:connection_established` | Firewall/port/scheme; `forceTLS` follows `VITE_REVERB_SCHEME` |
| Channel subscribed? | WS messages contain `pusher_internal:subscription_succeeded` for `private-users.7`; Console shows no "Real-time channel subscription failed" | See 4.6 (`/broadcasting/auth`) |
| Event received? | WS message with event `notification.created` and `{"notification_id":"..."}` | Event name must match `broadcastAs()` — listener uses a leading dot: `.notification.created` |
| Feed fetched? | Network: `GET /notifications/feed` right after the WS message | `notifications.js` loaded? 429 (4.3)? 401/419 session? |

While the socket is unhealthy, `notifications.js` keeps polling every 5 s (30 s in a hidden tab), so a missing toast
for longer than ~5 s means the polling path is broken too (see 2.15).

Known behaviour, not a bug: each open tab has its own WebSocket and shows its own toast.

> **বাংলায়:** Real-time সমস্যা খোঁজার সময় শিকলের মতো ধাপে ধাপে দেখুন: ডাটাবেসে নোটিফিকেশন আছে কি না → Reverb চলছে কি
> না → ব্রাউজারে WebSocket কানেক্ট হয়েছে কি না → চ্যানেল subscribe হয়েছে কি না → ইভেন্ট এসেছে কি না → feed থেকে ডাটা
> এসেছে কি না। যে ধাপে ভাঙছে, সমস্যা সেখানেই।

## 4.6 `POST /broadcasting/auth` returns 403 (or 401/419)

Echo calls this framework route before joining a private channel. Laravel runs the callback in `routes/channels.php`:

```php
Broadcast::channel('users.{userId}', function (User $user, $userId) {
    return $user->id === (int) $userId && $user->is_active;
});
```

| Status | Cause | Fix |
|---|---|---|
| 403 | Subscribing to another user's channel (the `<meta name="user-id">` was edited, or a stale page after logging in as someone else) | Reload the page; the meta tag must contain the logged-in user's id |
| 403 | User was deactivated (`is_active = 0`) | Expected |
| 401 / redirect to login | Session expired or no session cookie (e.g. Reverb host on another domain without cookies) | Log in again; serve app and auth from the same site |
| 419 | CSRF token missing/old — Echo reads `<meta name="csrf-token">` | Reload the page |

Tests: `RealtimeAndDeliveryTest::test_user_can_subscribe_only_to_their_own_private_channel`,
`test_guest_cannot_authorize_a_private_channel`.

## 4.7 Scheduler not running / SLA reminders missing

**Why:** `routes/console.php` defines `Schedule::command('tickets:check-sla')->everyMinute()->withoutOverlapping()`,
but **nothing runs it** unless `php artisan schedule:work` (local) or the cron line
`* * * * * php artisan schedule:run` (server) is active.

**Check, in order:**
1. `php artisan schedule:list` → the task is listed with its next run time.
2. Is `schedule:work` running in a terminal (with PHP 8.4)? On a server: `crontab -l` and the cron log.
3. Run the command by hand: `php artisan tickets:check-sla` → "SLA check finished: N breach notification(s) sent…".
4. "0 sent" but you expected one → the ticket does not match:
   ```sql
   SELECT id, status, due_at, sla_notified_at FROM tickets
   WHERE status IN ('open','assigned','in_progress') AND due_at < NOW() AND sla_notified_at IS NULL;
   ```
   Already notified (`sla_notified_at` set), resolved, or `due_at` in the future. Remember timestamps are in
   `Asia/Dhaka` (`APP_TIMEZONE`) — a wrong timezone makes `NOW()` comparisons look off by hours.
5. Notified but nobody saw it → recipient = assigned **active** agent, else every active admin; check `notifications`.
6. A previous run is "stuck" → `withoutOverlapping()` holds a lock in the cache store; it expires after 24 h by
   default. `php artisan schedule:clear-cache` removes overlap locks.

The command returns a non-zero exit code when one or more tickets failed (`report($exception)` writes them to the log)
— one broken ticket does not stop the others.

## 4.8 Attachment errors (422, 403, 404)

| Symptom | Cause | Check / fix |
|---|---|---|
| Validation error "Allowed file types: pdf, png, …" | Extension **or** detected MIME type not allowed (`extensions:` + `mimes:` rules) — e.g. `report.pdf` that is really an `.exe` | `config/resolvedesk.php` → `allowed_extensions`; `fileinfo` extension enabled in `php.ini` |
| "Each attachment may be at most 5 MB" | `max_file_kb` | Also check PHP's own limits: `upload_max_filesize` and `post_max_size` in `C:\php-8.4.28\php.ini` — if PHP rejects the upload first, Laravel sees no file or an empty POST (often a confusing 419 or "field required") |
| More than 5 files rejected | `max_files` | Expected |
| **403** on download | `TicketPolicy::downloadAttachment()`: user cannot view the ticket, the attachment belongs to a **different** ticket than the URL, or it belongs to an internal note and the user is a customer | Expected security behaviour; verify with `Gate::forUser($user)->allows('downloadAttachment', [$ticket, $attachment])` in tinker |
| **404** on download | Route model binding: attachment id does not exist → 404; or the row exists but the file is missing on disk → log `Attachment file missing on disk` | `Storage::disk('local')->exists($attachment->storage_path)`; files live under `storage/app/private/ticket-attachments/YYYY/MM/` — restore from backup ([19](19-BACKUP-AND-RECOVERY.md)) |
| Files on disk without DB rows | A failed transaction whose cleanup also failed (log `Could not delete orphaned attachment file`) | Safe to delete manually after checking `ticket_attachments.storage_path` |

## 4.9 Ticket scope errors on create

Messages from `TicketService::resolveScope()` (shown under the project/client field):
"You can only create tickets for projects you support." (agent not in `project_user`), "The selected project is not used
by this client organization." (no `client_project` row), "Your account is not linked to a client organization…"
(standalone customer chose a project), "Please choose an active project." Check the pivot tables:

```sql
SELECT * FROM project_user WHERE user_id = 4;
SELECT * FROM client_project WHERE client_id = 1;
SELECT id, role, client_id, is_active FROM users WHERE id = 9;
```

Empty project dropdown → `GET /tickets/project-options?client_id=…` in the Network tab; it returns only projects the
**current** user may choose (`TicketController::selectableProjects()`).

## 4.10 Tests hang or fail in V2-specific ways

| Symptom | Cause | Fix |
|---|---|---|
| `php artisan test` hangs with no output | MariaDB is stopped — each test waits for the connection timeout | Start MariaDB, `Ctrl+C`, run again |
| `MySqlConcurrencyTest` fails | It opens a second real connection (`mysql_second`) and commits for real, then truncates tables in `tearDown()`; it needs MySQL/MariaDB | Run against `resolvedesk_testing` on MariaDB, never SQLite and never the dev DB |
| A request token test fails with 422 | New test forgot `request_token` (`(string) Str::uuid()`) | Every ticket-creation POST needs it since V2 |
| Edit test returns a redirect with error | Missing `version` in the PUT data | Send `'version' => $ticket->version` |
| Broadcasting errors in tests | `phpunit.xml` sets `BROADCAST_CONNECTION=null`; tests that need events use `Event::fake()` | Do not change `phpunit.xml` to `reverb` |

## 4.11 `php artisan serve` uses the wrong database

`serve` passes only a short allow-list of environment variables (e.g. `APP_ENV`, `PATH`) to the PHP server process it
starts, so `$env:DB_DATABASE="copy"; php artisan serve` still uses `DB_DATABASE` from `.env`. Change `.env`
temporarily, or run `php -S 127.0.0.1:8000 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`
in a shell where the variable is set.

## 4.12 Readiness and liveness checks

- `GET /up` (framework) → 200 if Laravel boots.
- `GET /health/ready` → `{"status":"ok"}` or **503** `{"status":"unavailable"}` when `SELECT 1` fails; the exception is
  reported to the log (search for the request id). It never shows error details.

---

## Final checklist after fixing anything

```bash
php artisan test                         # 170 feature tests (last run: 170 passed / 682 assertions), DB resolvedesk_testing
php artisan test --filter=CacheTest      # run one file/class
php artisan test --filter=test_feed_with_since_returns_new_notifications
```

Remove every `dd()`, `dump()` and temporary `Log::info()` before committing.
