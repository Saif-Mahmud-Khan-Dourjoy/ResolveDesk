# 23 — Security, Error Handling, Logging and Operations

This guide maps ResolveDesk's security, error handling, logging and monitoring to the **actual code**.
Everything marked "implemented" exists in the source; production server steps are documented in
[18 — Production deployment](18-PRODUCTION-DEPLOYMENT.md) but were **not executed** on a Linux server.

Related: [Backup and recovery](19-BACKUP-AND-RECOVERY.md), [Queue and email](14-QUEUE-AND-EMAIL-GUIDE.md),
[Notification system](13-NOTIFICATION-SYSTEM-GUIDE.md), [Debugging](11-DEBUGGING-GUIDE.md).

---

## 1. Security checklist mapped to code

### 1.1 Authentication — `app/Http/Controllers/AuthController.php`

| Control | Code |
|---|---|
| Password hashing | `Auth::attempt($credentials, ...)` compares with the stored bcrypt hash (`BCRYPT_ROUNDS=12`) |
| Login rate limit | 5 failed attempts per **email + IP**, 60 s lockout (`MAX_LOGIN_ATTEMPTS = 5`, `LOCKOUT_SECONDS = 60`) |
| Inactive account | After a correct password, `if (! Auth::user()->is_active) { Auth::logout(); ... }` |
| Session fixation | `$request->session()->regenerate();` after login |
| Logout | `Auth::logout(); session()->invalidate(); session()->regenerateToken();` |
| Deactivated while logged in | `ActiveUserMiddleware` (alias `active`) logs the user out on the next request (JSON 401 for AJAX) |
| No public registration | Admins create accounts (`UserController`, `role:admin`) |

```php
$throttleKey = Str::lower($credentials['email']) . '|' . $request->ip();

if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
    $seconds = RateLimiter::availableIn($throttleKey);
    throw ValidationException::withMessages([
        'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
    ]);
}
```

Note: the login limit shows a **validation message on the login form** (redirect with error / 422 JSON), not the 429
page. Password reset is not implemented (admins reset passwords on the user edit page).

### 1.2 Authorization

Two layers:

1. **Middleware (broad, per route group)** — `routes/web.php`:
   - `auth` + `active` on every logged-in route;
   - `role:admin` (`app/Http/Middleware/RoleMiddleware.php`) on users, categories, projects, clients, reports/export,
     assign and auto-assign. `RoleMiddleware` aborts with 403 when `! in_array($user->role, $roles, true)`.
2. **Policy (per record)** — `app/Policies/TicketPolicy.php`, used as `Gate::authorize('resolve', $ticket)` in
   controllers and `@can(...)` in Blade (the Blade check only hides buttons; the controller check is the real one).

| Policy method | Rule |
|---|---|
| `view` | admin, or ticket creator, or assigned agent |
| `create` | everyone, except a customer whose client organization is inactive |
| `update` | not closed; admin, or creator while Open and unassigned |
| `assign` | admin and ticket status is active |
| `start` / `resolve` | admin or assigned agent + `Ticket::TRANSITIONS` allows it |
| `close` / `reopen` | admin or creator + transition allowed |
| `comment` | can view and ticket not closed |
| `addInternalNote` | `$user->isStaff() && $this->comment($user, $ticket)` |
| `downloadAttachment` | attachment belongs to this ticket, user can view the ticket, internal-note files staff-only |

```php
public function downloadAttachment(User $user, Ticket $ticket, TicketAttachment $attachment): bool
{
    if ($attachment->ticket_id !== $ticket->id || ! $this->view($user, $ticket)) {
        return false;
    }

    if ($attachment->ticket_comment_id !== null && $attachment->comment->isInternal()) {
        return $user->isStaff();
    }

    return true;
}
```

Other server-side checks:

- `TicketCommentController::store()` → `Gate::authorize('comment', $ticket)`, and **additionally**
  `Gate::authorize('addInternalNote', $ticket)` when `visibility=internal` (a customer who edits the HTML form gets 403).
- `StoreTicketRequest::authorize()` → `$this->user()->can('create', Ticket::class)`.
- `TicketController::projectOptions()` (AJAX dropdown) → `Gate::authorize('create', Ticket::class)` and only returns
  projects the user may select.
- Scope rules (which project/client a role may use) are enforced in `TicketService::resolveScope()` inside the
  transaction, not trusted from the form.

### 1.3 Private broadcast channel — `routes/channels.php`

```php
Broadcast::channel('users.{userId}', function (User $user, $userId) {
    return $user->id === (int) $userId && $user->is_active;
});
```

Echo calls `POST /broadcasting/auth` (session + CSRF). Subscribing to another user's channel returns 403. This was
verified live with Reverb (see `DEVELOPMENT_PROGRESS.md`: "subscribing to another user's channel refused (AuthError)").
The event payload carries **only** `notification_id`; the browser then fetches the content through the authorized
`/notifications/feed`.

### 1.4 Input and output security

| Risk | Protection in ResolveDesk |
|---|---|
| **CSRF** | Every state change is POST/PUT/PATCH (`routes/web.php` header comment); forms use `@csrf`; `public/js/notifications.js` sends `X-CSRF-TOKEN`. Missing/expired token → 419 page (`resources/views/errors/419.blade.php`) |
| **Validation** | `StoreTicketRequest` (subject max 255, description max 5000, active category via `Rule::exists(...)->where('is_active', true)`, priority `Rule::in`, `request_token` uuid, attachment rules); inline `$request->validate()` in other controllers (e.g. comments, feed `since`, list filters) |
| **Mass assignment** | `$fillable` whitelists. `Ticket::$fillable = ['subject','description','category_id','priority']`; `status`, `client_id`, `project_id`, `assigned_to`, `version`, `ticket_number` are set explicitly by `TicketService` (`$ticket->client_id = $clientId; // validated scope`). `TicketAttachment::$fillable = []`. `User::client_id` not fillable — `UserController` sets it after business checks |
| **SQL injection** | Eloquent / query builder with bound parameters; no user input in raw SQL. Sorting uses fixed `orderBy('name')` / `orderBy('code')` (no user-controlled sort column) |
| **XSS** | Blade `{{ }}` escapes output. Notification toasts/dropdown in `public/js/notifications.js` use `textContent`, never `innerHTML` (`message.textContent = item.message;`) |
| **IDOR** | Every `{ticket}` route goes through `TicketPolicy`; notifications use `$request->user()->notifications()->findOrFail($id)` → 404 for someone else's id; attachment must match the ticket in the URL |
| **Cross-client isolation** | Customers only see tickets they created; a customer's `client_id` is taken from their account, form input ignored (`TicketService::resolveScope()`); private channel per user |
| **Unauthorized field modification** | Only fillable fields from validated data are updated; edits need the current `version` (optimistic lock → 409) |

> **IDOR (বাংলায়):** Insecure Direct Object Reference — URL-এর id বদলে অন্যের data দেখার চেষ্টা। যেমন customer
> `/tickets/15` থেকে `/tickets/16` লিখল। ResolveDesk-এ প্রতিটি request-এ policy চেক করে ticket-টি তার কিনা; না হলে 403।

IDOR examples you can demonstrate:

| Attempt | Result |
|---|---|
| Customer opens `GET /tickets/{id}` of another customer | 403 (`TicketPolicy::view`) |
| Customer downloads `GET /tickets/{ownTicket}/attachments/{attachmentOfOtherTicket}` | 403 (`ticket_id` mismatch) |
| Customer downloads a file attached to an internal note on their own ticket | 403 (staff-only) |
| User marks `POST /notifications/{otherUsersId}/read` | 404 |
| Agent subscribes to `private-users.{otherId}` | 403 on `/broadcasting/auth` |
| Customer posts `visibility=internal` | 403 (`addInternalNote`) |

### 1.5 Attachment security — `AttachmentService`, `TicketAttachmentController`

| Control | Code |
|---|---|
| Allowed types | `config/resolvedesk.php`: `pdf, png, jpg, jpeg, txt, docx, xlsx` |
| Size/count | 5 MB per file (`ATTACHMENT_MAX_KB=5120`), max 5 files per request |
| Extension **and** content check | `'extensions:' . $extensions` (file name) + `'mimes:' . $extensions` (MIME detected from content) |
| Generated file name | `Str::uuid() . '.' . $extension` under `ticket-attachments/YYYY/MM` — the user's name is never a path (no `../../.env` traversal) |
| Private storage | disk `local` → `storage/app/private`, no public URL |
| Download | only via `TicketAttachmentController::download()` after `Gate::authorize('downloadAttachment', ...)` |
| No inline execution | `$disk->download(...)` sends `Content-Disposition: attachment` plus `X-Content-Type-Options: nosniff` |
| No orphan files | files stored before the transaction, deleted again if it fails (`deleteFiles()`) |

Not implemented: antivirus scanning, attachment deletion UI (documented limitations).

### 1.6 CSV formula injection — `ReportController::safeCsvValue()`

```php
if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
    return "'" . $value;
}
```

A customer could write a ticket subject like `=HYPERLINK("http://evil","click")`. When an admin opens the export in
Excel, the cell would run as a formula. Prefixing `'` makes Excel treat it as text. The export is admin-only
(`role:admin`) and rate limited (`throttle:exports`).

> **Formula injection (বাংলায়):** CSV file-এ কোনো cell `=`, `+`, `-` বা `@` দিয়ে শুরু হলে Excel সেটাকে formula হিসেবে
> চালায়। Attacker ticket-এর subject-এ formula লিখে রাখলে admin export খুললেই সেটা চলতে পারে। শুরুতে `'` যোগ করলে
> Excel সেটাকে সাধারণ text হিসেবে দেখায়।

### 1.7 Concurrency and duplicate requests (security-relevant correctness)

- Optimistic locking: `version` column; stale edit → `TicketConflictException::staleVersion()` (409 / redirect back).
- Row locks: `lockForUpdate()` on the ticket inside `DB::transaction(..., 3)` (3 attempts on deadlock).
- Assignment: global `Cache::lock('ticket-assignment', 10)` with `block(5)`.
- Idempotent ticket creation: hidden `request_token` (uuid, unique) → a double submit returns the first ticket
  (`UniqueConstraintViolationException` handled in `TicketService`).

Details: concurrency documentation and `DEVELOPMENT_PROGRESS.md` (multi-process checks).

---

## 2. Rate limiting

Defined in `app/Providers/AppServiceProvider.php::configureRateLimiting()` and used as `->middleware('throttle:NAME')`
in `routes/web.php`:

```php
$byUserOrIp = fn (Request $request) => $request->user()?->id ?: $request->ip();

RateLimiter::for('ticket-create', fn (Request $request) => Limit::perMinute(10)->by('ticket-create:' . $byUserOrIp($request)));
```

| Limiter | Route(s) | Limit | Key |
|---|---|---|---|
| login (manual, `AuthController`) | `POST /login` | 5 failed attempts / 60 s | email + IP |
| `ticket-create` | `POST /tickets` | 10 / min | user id, else IP |
| `comments` | `POST /tickets/{ticket}/comments` (comments, internal notes, attachment uploads) | 30 / min | user id, else IP |
| `notifications` | `GET /notifications/feed` | 120 / min (polling every 5 s = 12/min per tab) | user id, else IP |
| `exports` | `GET /reports/export` | 5 / min | user id, else IP |
| `throttle:60,1` | `GET /health/ready` | 60 / min | IP (guest) |

Why per user id: colleagues behind one office IP do not block each other. Guests are limited per IP.
When exceeded, Laravel throws `ThrottleRequestsException` → HTTP **429** with `Retry-After` header; HTML requests see
`resources/views/errors/429.blade.php` ("Please slow down"), JSON requests get a JSON message.

Counters live in the cache store (`CACHE_STORE=database` → `cache` table).

---

## 3. Error handling

`bootstrap/app.php` → `withExceptions()` is empty: Laravel's standard handler is used, plus `render()` on the custom
exception.

| Situation | Exception / mechanism | Response |
|---|---|---|
| Invalid input, invalid project-client combination, no eligible agent, invalid status transition | `ValidationException` (from `validate()` or `ValidationException::withMessages()` in services) | Form: redirect back with errors + old input. AJAX: **422** JSON |
| Stale version / request token reused with different content | `App\Exceptions\TicketConflictException` | AJAX: **409** `{"message": ...}`. Form: `back()->withInput()->with('error', ...)` (input kept) |
| Assignment lock busy | `LockTimeoutException` caught in `TicketAssignmentService::withAssignmentLock()` → `ValidationException` | "Another assignment is being processed right now. Please try again in a moment." |
| Policy/role denied | `AuthorizationException` / `abort(403)` | **403** page `errors/403.blade.php` |
| Missing/expired CSRF token | `TokenMismatchException` | **419** page `errors/419.blade.php` |
| Too many requests | `ThrottleRequestsException` | **429** page `errors/429.blade.php` |
| Attachment file missing | `abort(404)` + `Log::error(...)` | 404 |
| Deactivated user (AJAX) | `ActiveUserMiddleware` | 401 JSON |
| Reverb down | try/catch in `TicketNotifier::broadcast()` | Operation succeeds; `Log::warning`; polling delivers |
| Email dispatch failure | try/catch in `TicketNotifier` after commit | Operation succeeds; `Log::error('Could not queue ticket email')` |
| Email job fails 3 times | `SendTicketEmail::failed()` | Job in `failed_jobs`; `Log::error('Ticket email failed permanently')` |
| Unexpected error | standard handler | Production (`APP_DEBUG=false`): generic error page, no stack trace or SQL; details only in the log |

```php
class TicketConflictException extends Exception
{
    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withInput()->with('error', $this->getMessage());
    }
}
```

Custom error views exist for 403, 419 and 429 only. 404/500/503 use Laravel's built-in pages (safe when
`APP_DEBUG=false`). There is no `409.blade.php` because form conflicts redirect back instead of rendering a page.

---

## 4. Logging

### 4.1 Request id — `app/Http/Middleware/AddRequestContext.php`

Appended to the `web` group in `bootstrap/app.php` (`$middleware->web(append: [AddRequestContext::class]);`).

```php
$incomingId = (string) $request->headers->get('X-Request-Id', '');
$requestId = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incomingId) ? $incomingId : (string) Str::uuid();

Log::withContext([
    'request_id' => $requestId,
    'user_id' => $request->user()?->id,
]);

$response = $next($request);
$response->headers->set('X-Request-Id', $requestId);
```

- Every log line in that request gets `{"request_id":"...","user_id":7}`.
- The response header `X-Request-Id` lets a user/support person report the id; an admin then runs
  `grep <request-id> storage/logs/laravel-*.log`.
- The incoming header is accepted only if it is a short safe value (prevents log injection).
- Only routes in the `web` group get the context (all app routes, including `/broadcasting/auth`); `/up` and
  queue jobs do not.

### 4.2 Channels

- Local: `LOG_STACK=single` → `storage/logs/laravel.log`.
- Production: `LOG_STACK=daily` → `laravel-YYYY-MM-DD.log`, kept `LOG_DAILY_DAYS` (14) days; `LOG_LEVEL=warning`
  recommended so routine info lines are skipped.
- Queries are not logged (no query listener). Do not enable query logging in production.

### 4.3 What is logged / never logged

Logged (examples from code): `ticket_id`, `attachment_id`, `delivery_key`, `ticket_number`, error messages, storage path
of an orphaned file.

Never logged on purpose: passwords, session cookies, CSRF tokens, Reverb secret, ticket descriptions/comment text,
attachment content. Exception: `SendTicketEmail::failed()` logs the recipient email address (see limitations).

### 4.4 Business activity history vs technical logs

| | Activity history | Technical logs |
|---|---|---|
| Where | `ticket_activities` table (database) | `storage/logs/*.log` (files) |
| Audience | Users, admins (ticket page timeline; internal activities staff-only) | Developers / operators |
| Content | "Sarah assigned the ticket", status changes, priority/due changes, SLA breach | Exceptions, failed emails, broadcast failures, request ids |
| Lifetime | Permanent business record, backed up | Rotated after 14 days |
| Written | Inside the same transaction as the change | Whenever something technical happens, independent of transactions |

> **বাংলায়:** Activity history হলো ব্যবসায়িক ইতিহাস — কে, কখন, ticket-এ কী করেছে; এটা database-এ থাকে এবং
> user-রা দেখতে পায়। Technical log হলো developer-এর জন্য — error, failure, request id; এটা file-এ থাকে এবং কিছুদিন পর
> মুছে যায়।

---

## 5. Monitoring and operations

### 5.1 Five different "health" questions

| Question | How to check | Implemented |
|---|---|---|
| **Liveness** — is Laravel answering? | `GET /up` (`bootstrap/app.php` `health: '/up'`) | Yes |
| **Readiness** — can it serve real requests (DB reachable)? | `GET /health/ready` → `{"status":"ok"}` or 503 `{"status":"unavailable"}` | Yes |
| **Queue worker** running and keeping up? | `supervisorctl status`, backlog SQL, `queue:failed` | Manual check |
| **Scheduler** running? | cron entry, `schedule:list`, SLA reminders appear | Manual check |
| **Reverb** available? | `supervisorctl status resolvedesk-reverb`, browser WebSocket connects; the UI falls back to polling | Manual check |

> **Liveness vs readiness (বাংলায়):** Liveness মানে "process টা বেঁচে আছে কিনা" — না থাকলে restart করতে হবে।
> Readiness মানে "এখন কি আসল কাজ (database সহ) করতে পারবে" — database বন্ধ থাকলে app বেঁচে আছে, কিন্তু ready না;
> load balancer তখন এই server-এ traffic পাঠাবে না।

Public health endpoints return only `ok` / `unavailable` — no versions, hostnames, or error details.

### 5.2 Operator commands

```bash
# Application logs
tail -f storage/logs/laravel-$(date +%F).log
grep '<request-id>' storage/logs/laravel-*.log
php artisan pail                          # dev only: laravel/pail is a require-dev package

# Failed jobs
php artisan queue:failed                  # list
php artisan queue:retry <uuid>            # retry one (or: queue:retry all)
php artisan queue:forget <uuid>           # delete one after investigation

# Database connectivity
curl https://<support.example.com>/health/ready
php artisan db:show

# Scheduler
php artisan schedule:list
php artisan tickets:check-sla             # run the SLA check once by hand

# Processes
sudo supervisorctl status
sudo supervisorctl restart resolvedesk-reverb

# Disk and attachment storage usage
df -h /var/www
du -sh storage/app/private/ticket-attachments
du -sh storage/logs
```

Queue backlog (database queue):

```sql
-- Waiting jobs, and how old the oldest one is
SELECT queue, COUNT(*) AS waiting, FROM_UNIXTIME(MIN(available_at)) AS oldest_available
FROM jobs
WHERE reserved_at IS NULL
GROUP BY queue;

-- Jobs currently being processed
SELECT id, attempts, FROM_UNIXTIME(reserved_at) AS reserved
FROM jobs WHERE reserved_at IS NOT NULL;

-- Failures in the last day
SELECT COUNT(*) FROM failed_jobs WHERE failed_at > NOW() - INTERVAL 1 DAY;

-- Attachment storage according to the database
SELECT COUNT(*) AS files, ROUND(SUM(size) / 1024 / 1024, 1) AS megabytes FROM ticket_attachments;
```

A growing `waiting` count with an old `oldest_available` means the worker is stopped or too slow.

### 5.3 Future infrastructure integrations (not part of the app)

Uptime monitor polling `/up` and `/health/ready`; Prometheus + Grafana for metrics; centralized logs (ELK, Loki);
error tracking (Sentry); alert on `failed_jobs` growth and disk usage. These are infrastructure, deliberately not
built into the Laravel code.

---

## 6. Dependency audits

Commands run on the Windows dev machine with PHP 8.4.28 / Composer 2.8.8 (2026-11-26):

| Command | Result |
|---|---|
| `composer audit` | "No security vulnerability advisories found." |
| `npm audit` | "found 0 vulnerabilities" (also recorded in `DEVELOPMENT_PROGRESS.md` after the npm cleanup) |

Only four npm packages remain (`laravel-echo`, `pusher-js`, `vite`, `laravel-vite-plugin`, all dev dependencies used
for the build). Run both audits before each release; do not apply breaking upgrades automatically.

---

## 7. Production configuration differences

| Setting | Local (Windows/XAMPP) | Production |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | `production` / `false` |
| `APP_URL` | `http://127.0.0.1:8000` | `https://<support.example.com>` |
| Web server | `php artisan serve` / `php -S` | Nginx + PHP-FPM |
| Database | MariaDB 10.4, `root`, empty password | MySQL 8, least-privilege `resolvedesk_app` |
| `LOG_STACK` / `LOG_LEVEL` | `single` / `debug` | `daily` / `warning` |
| `SESSION_SECURE_COOKIE` | unset (HTTP) | `true` |
| `MAIL_MAILER` | `log` | `smtp` |
| Reverb | `http`, port 8080, direct | `wss` on 443 via Nginx proxy, server bound to `127.0.0.1:8080` |
| Processes | started by hand | Supervisor (queue, Reverb), cron (scheduler) |
| Caches | not cached | `config:cache`, `route:cache`, `view:cache` |
| `Model::preventLazyLoading()` | on (throws on N+1) | off (`! $this->app->isProduction()`) |

---

## 8. Unresolved findings and known limitations

From `DEVELOPMENT_PROGRESS.md` and review of the configuration:

- **Not verified:** Linux/Nginx deployment, MySQL 8 (only MariaDB 10.4 tested), Gmail/SMTP delivery, real-browser
  rendering of the WebSocket toast, backup restore drill.
- **Reverb allowed origins:** `config/reverb.php` has `'allowed_origins' => ['*']` (package default). For production
  this should be restricted to `['<support.example.com>']`; it is not changed in the code yet. Private channels are
  still protected by `/broadcasting/auth`.
- **Trusted proxies** are not configured (`bootstrap/app.php`). Fine for Nginx → PHP-FPM on one host; required if a
  load balancer/CDN is added, otherwise per-IP limits and HTTPS detection break.
- No antivirus scanning of attachments; no attachment deletion UI.
- Email dedupe uses the cache for 2 days; a crash between SMTP accept and cache write can send one duplicate.
- `SendTicketEmail::failed()` logs the recipient email address (personal data in technical logs).
- Password reset is not implemented (admin sets passwords).
- Login lockout uses a validation message instead of HTTP 429.
- Timestamps stored in `Asia/Dhaka`, not UTC.
- Reverb single server, no Redis scaling; each browser tab opens its own WebSocket and shows its own toast.
- Assignment lock is global and expires after 10 s; the row lock + status/version checks are the real guarantee.

---

## 9. Interview summary

"Security is layered: `auth` + `active` + `role` middleware for broad access, `TicketPolicy` for every ticket action
including internal notes and attachment downloads, a private `users.{id}` channel, CSRF, validation and `$fillable`
whitelists, Blade escaping and `textContent` for XSS, generated names and a private disk for files, and `'` prefixing
against CSV formula injection. Rate limits are named limiters keyed by user id. Expected failures become 422, 409 or
friendly messages; production hides stack traces. Every log line has a request id that is also returned as
`X-Request-Id`. `/up` is liveness, `/health/ready` is readiness; queue, scheduler and Reverb are checked with Supervisor,
`queue:failed`, the jobs table and `schedule:list`."
