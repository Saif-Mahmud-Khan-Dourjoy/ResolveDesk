# 09 — Interview Questions and Answers

Practical questions an interviewer may ask, answered for **this** project. Every answer has:

1. a **short answer** you can say in one or two sentences, then
2. **In ResolveDesk:** a real example (file + snippet or method name) so you can prove you know your own code.

Difficult ideas also have a **বাংলায়** block. Tricky follow-up questions are collected in
[Part 5](#part-5--tricky-follow-up-questions).

Related: [Live coding practice](08-LIVE-CODING-PRACTICE.md) · [System design guide](10-SYSTEM-DESIGN-GUIDE.md) ·
[Business logic](06-BUSINESS-LOGIC-GUIDE.md) · [Notifications](13-NOTIFICATION-SYSTEM-GUIDE.md)

> Implemented vs. future: everything under "In ResolveDesk" is implemented. Redis, WebSockets (Reverb/Pusher),
> load balancers, read replicas and scheduled SLA escalation are **not** implemented — they are only discussed as
> future options.

---

## Part 1 — PHP fundamentals

### 1.1 Variables

**Q: How do variables work in PHP?**

**Answer:** A variable starts with `$`, is created when you assign it, and its type is decided by the value
(dynamic typing). Variables inside a function are local to that function.

**In ResolveDesk:** `AuthController::login()` builds a rate-limit key from two values:

```php
        $throttleKey = Str::lower($credentials['email']) . '|' . $request->ip();
```

`.` is the string concatenation operator. Double-quoted strings interpolate variables:
`"Ticket {$ticket->ticket_number} was created successfully."` in `TicketController::store()`.

**Follow-up: single vs double quotes?** Single quotes are literal; double quotes replace `$variables` and
`{$object->property}`. That is why notification texts in `TicketService` use double quotes.

### 1.2 Arrays

**Q: What kinds of arrays does PHP have?**

**Answer:** One array type that works as a list (`[1, 2, 3]`) or as an ordered key–value map
(`['open' => 'Open']`). Arrays are copied by value when assigned.

**In ResolveDesk:** allowed values are associative arrays used as "value => label":

```php
    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_ASSIGNED => 'Assigned',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_RESOLVED => 'Resolved',
        self::STATUS_CLOSED => 'Closed',
    ];
```

Common array functions used in the project: `array_keys(Ticket::PRIORITIES)` (validation),
`in_array($ticket->status, Ticket::ACTIVE_STATUSES, true)` (policy/service), `array_sum($statusCounts)`
(dashboard total), `implode(', ', $changedFields)` (activity text).

**Follow-up: why `in_array(..., true)`?** The third argument enables strict comparison (`===`), so `"0"` and `0`
or `null` and `""` are not treated as equal.

### 1.3 Functions

**Q: What is a function and what are Laravel helper functions?**

**Answer:** A named, reusable block of code with parameters and an optional return value. Laravel adds global helper
functions such as `now()`, `route()`, `view()`, `redirect()`, `old()`, `config()`.

**In ResolveDesk:** a small private helper in `TicketService`:

```php
    private function formatDate($date): string
    {
        return $date ? $date->format('d M Y, h:i A') : '-';
    }
```

and helpers everywhere, e.g. `redirect()->route('tickets.show', $ticket)` and `now()->addHours(...)`.

### 1.4 Classes and objects

**Q: What is the difference between a class and an object?**

**Answer:** A class is the blueprint (properties + methods); an object is one instance created with `new`.

**In ResolveDesk:** `TicketService::createTicket()` creates an object of the `Ticket` class and calls its methods:

```php
            $ticket = new Ticket();
            $ticket->fill($data);
            $ticket->created_by = $creator->id;
            // ...
            $ticket->save();
```

Each `Ticket` object represents one row of the `tickets` table (Active Record pattern).

### 1.5 Constructors

**Q: What is a constructor? What is constructor property promotion?**

**Answer:** `__construct()` runs automatically when an object is created. PHP 8 *property promotion* declares and
assigns a property directly in the constructor parameter list.

**In ResolveDesk:**

```php
    public function __construct(private TicketService $ticketService)
    {
    }
```

(`TicketController`, `TicketCommentController`) — this one line creates the private property `$this->ticketService`.
The job uses the same feature:

```php
    public function __construct(
        public string $recipientEmail,
        public string $recipientName,
        public array $emailData,
    ) {
    }
```

### 1.6 Inheritance

**Q: What is inheritance?**

**Answer:** A child class `extends` a parent class and gets its public/protected properties and methods; it can add
or override behaviour. PHP supports single inheritance only.

**In ResolveDesk:** `class User extends Authenticatable` (gets password/remember-token login support from
Laravel), `class Ticket extends Model` (gets `save()`, `find()`, relationships...), `class TicketController extends
Controller`. Tests call the parent's setup: `parent::setUp();` in `TicketWorkflowTest::setUp()`.

### 1.7 Interfaces

**Q: What is an interface?**

**Answer:** A contract: a list of methods (or just a marker) a class promises to provide. Code can depend on the
interface instead of a concrete class. A class can implement many interfaces.

**In ResolveDesk:** `class SendTicketEmail implements ShouldQueue`. `ShouldQueue` is a **marker interface**: when
Laravel sees it on a job, it pushes the job to the queue instead of running it immediately.

### 1.8 Traits

**Q: What is a trait and why use it instead of inheritance?**

**Answer:** A trait is a reusable group of methods copied into a class with `use`. It solves "I need this behaviour
in many unrelated classes" without multiple inheritance.

**In ResolveDesk:** `use HasFactory, Notifiable;` in `User` (factories + `$user->notify()`, `$user->notifications()`),
`use Queueable;` in `SendTicketEmail`, `use RefreshDatabase;` in every feature test.

### 1.9 Namespaces

**Q: What are namespaces?**

**Answer:** They group classes and prevent name collisions (like folders for class names). With Composer's PSR-4
autoloading, `App\` maps to the `app/` folder, so `App\Services\TicketService` lives in
`app/Services/TicketService.php`.

**In ResolveDesk:** `namespace App\Services;` at the top of `TicketService.php`, and `use App\Models\Ticket;` to
import a class by its short name. Two different `Carbon` classes exist; the project imports
`Illuminate\Support\Carbon` explicitly in `Ticket.php`.

### 1.10 Access modifiers

**Q: public, protected, private?**

**Answer:** `public` = accessible everywhere; `protected` = the class and its children; `private` = only the class
itself.

**In ResolveDesk:**
- `public const DASHBOARD_CACHE_KEY = 'dashboard:admin:stats';` — shared with `DashboardController` and `CategoryController`.
- `private const PER_PAGE = 15;` in `TicketController` — an internal detail.
- `protected $fillable = [...]` — Laravel's parent `Model` class reads it, so it must be at least protected.
- `private function lockTicket(Ticket $ticket): Ticket` and `private function performAssignment(...)` — helpers that
  must not be called from outside the service (they assume a transaction is already open).

### 1.11 Static methods

**Q: When is a method static?**

**Answer:** A static method belongs to the class, not an object; call it with `ClassName::method()`. Use it when the
method needs no object state.

**In ResolveDesk:** `Ticket::calculateDueAt(Carbon $createdAt, string $priority)` and
`Ticket::makeTicketNumber(int $id)` only need their arguments, so they are static and can be used by the service,
the factory and the seeder. `Category::activeForDropdown()` and `Category::clearActiveCache()` are static too.

**Follow-up: is `Cache::remember()` a static method?** It only looks static. `Cache` is a **facade**: the static call
is forwarded to a real cache object from the service container (see 2.8).

### 1.12 Closures

**Q: What is a closure? What does `use` do?**

**Answer:** An anonymous function that can be stored or passed as an argument. `use ($var)` copies outer variables
into the closure (by value).

**In ResolveDesk:** every transaction is a closure:

```php
        $ticket = DB::transaction(function () use ($data, $creator) {
            // ... all database work ...
            return $ticket;
        });
```

`Ticket::scopeVisibleTo()` uses a closure to group conditions in brackets:

```php
        return $query->where(function (Builder $subQuery) use ($user) {
            $subQuery->where('created_by', $user->id)
                ->orWhere('assigned_to', $user->id);
        });
```

> **বাংলায়:** Closure হলো নামহীন function, যাকে variable-এর মতো অন্য function-এ পাঠানো যায়। `use ($user)`
> দিয়ে বাইরের variable ভেতরে আনা হয়। `DB::transaction()` closure-টি চালায় এবং ভেতরের সব কাজ একসাথে commit বা
> rollback করে।

### 1.13 Arrow functions

**Q: How is an arrow function different from a closure?**

**Answer:** `fn ($x) => expression` is a short closure with a **single expression** that captures outer variables
(and `$this`) automatically — no `use` needed.

**In ResolveDesk:** `NotificationController::feed()`:

```php
            'notifications' => $latest->map(fn ($notification) => $this->formatNotification($notification)),
```

and in tests: `Queue::assertPushed(SendTicketEmail::class, fn ($job) => $job->recipientEmail === 'customer@example.com');`

### 1.14 Null coalescing (and nullsafe)

**Q: What do `??`, `??=` and `?->` do?**

**Answer:** `a ?? b` returns `a` if it exists and is not null, otherwise `b` (no "undefined" warning). `a ??= b`
assigns `b` only if `a` is null. `?->` calls a method/property only if the object is not null.

**In ResolveDesk:**

```php
        return self::STATUSES[$this->status] ?? $this->status;             // Ticket::statusLabel()
            'title' => $data['title'] ?? 'Notification',                    // NotificationController
            'password' => static::$password ??= Hash::make('password'),      // UserFactory (hash once)
            'error' => $exception?->getMessage(),                           // SendTicketEmail::failed()
```

Blade: `{{ $ticket->assignedAgent?->name ?? '—' }}` — unassigned tickets show a dash instead of crashing.

### 1.15 Exceptions

**Q: How do you handle errors with exceptions?**

**Answer:** Code `throw`s an exception object; a `try/catch` higher up handles it. Uncaught exceptions go to Laravel's
exception handler, which turns known types into HTTP responses (404, 403, 422...).

**In ResolveDesk:**
- Business rule failure: `throw ValidationException::withMessages(['ticket' => 'This action is not allowed while the ticket is Closed.']);`
  (`TicketService::ensureActionAllowed()`) → redirect back with the message.
- An exception inside `DB::transaction()` rolls the transaction back and is re-thrown.
- `try { SendTicketEmail::dispatch(...); } catch (Throwable $exception) { Log::error(...); }` in `queueEmail()` — a
  queue problem must not show an error for a ticket change that is already committed.
- `firstOrFail()` / `findOrFail()` throw `ModelNotFoundException` → 404. `Gate::authorize()` throws
  `AuthorizationException` → 403. `abort(403, ...)` in `RoleMiddleware`.

### 1.16 Type declarations

**Q: Why use type declarations?**

**Answer:** Parameter, return and property types document the code and make PHP throw a `TypeError` when a wrong type
is passed. `?Type` means "Type or null"; `void` means "returns nothing".

**In ResolveDesk:**

```php
    public function autoAssign(Ticket $ticket, User $admin): ?User                 // returns an agent or null
    private function recordActivity(Ticket $ticket, User $user, string $action, ?string $oldValue, ?string $newValue): void
    public function handle(Request $request, Closure $next, string ...$roles): Response   // variadic in RoleMiddleware
    public int $tries = 3;                                                           // typed property in SendTicketEmail
```

**Follow-up:** the files do not declare `strict_types=1`, so PHP uses *coercive* mode for scalars (e.g. `"5"` can be
accepted for an `int` parameter). Class types like `Ticket` are always checked.

---

## Part 2 — Laravel fundamentals

### 2.1 MVC

**Q: Explain MVC in your project.**

**Answer:** **Model** = data and rules about one table, **View** = HTML output, **Controller** = receives the
request, coordinates, returns a response.

**In ResolveDesk:** `GET /tickets/{ticket}` → `TicketController::show()` (controller) → loads the `Ticket` model with
relationships → returns `resources/views/tickets/show.blade.php` (view). For actions that touch several tables
(status + activity + notification + email + cache), the controller delegates to `App\Services\TicketService` so the
controller stays thin.

> **বাংলায়:** MVC মানে কাজ ভাগ করে রাখা: Model ডেটা ও নিয়ম জানে, View শুধু দেখায়, Controller দুটোকে যুক্ত করে।
> ResolveDesk-এ জটিল ব্যবসায়িক নিয়মগুলো আলাদা `TicketService`-এ রাখা হয়েছে, যাতে controller ছোট ও পরিষ্কার থাকে।

### 2.2 Routing

**Q: How are routes defined?**

**Answer:** In `routes/web.php`, mapping an HTTP method + URL to a controller method, usually with a **name** so views
use `route('name')` instead of hard-coded URLs.

**In ResolveDesk:**

```php
    Route::resource('tickets', TicketController::class)->except(['destroy']);
    Route::post('/tickets/{ticket}/resolve', [TicketController::class, 'resolve'])->name('tickets.resolve');
```

`Route::resource` creates the standard index/create/store/show/edit/update routes. Routes are grouped by middleware:
`guest` (login), `['auth', 'active']` (everything else) and nested `role:admin` (assign, users, categories).
Every data-changing route uses POST/PUT/PATCH/DELETE, never GET. List them with `php artisan route:list --except-vendor`.

### 2.3 Controllers

**Q: What belongs in a controller?**

**Answer:** Request handling: authorize, validate, call the model/service, return a view or redirect. Not complex
business logic.

**In ResolveDesk:** `TicketController::resolve()` is the pattern for every workflow action:

```php
    public function resolve(Request $request, Ticket $ticket)
    {
        Gate::authorize('resolve', $ticket);

        $validated = $request->validate([
            'resolution_note' => ['required', 'string', 'max:5000'],
        ]);

        $this->ticketService->resolve($ticket, $validated['resolution_note'], $request->user());

        return back()->with('success', 'Ticket marked as Resolved.');
    }
```

Plain CRUD (users, categories) stays inside `UserController` / `CategoryController` — no service needed.

### 2.4 Middleware

**Q: What is middleware?**

**Answer:** Code that runs before (or after) the controller for every request in a route group — a filter. It can
stop the request (redirect, 403) or pass it on with `$next($request)`.

**In ResolveDesk:** aliases in `bootstrap/app.php`:

```php
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'active' => ActiveUserMiddleware::class,
        ]);
```

- `auth` (Laravel): must be logged in, else redirect to `/login`.
- `active` (`ActiveUserMiddleware`): logs out a user whose account was deactivated **while logged in**; AJAX gets
  401 JSON.
- `role:admin` (`RoleMiddleware`): `string ...$roles` receives `['admin']`; other roles get `abort(403)`.
- The `web` group (Laravel) adds sessions, cookies and CSRF checking.

> **বাংলায়:** Middleware হলো দরজার পাহারাদারের মতো। Request controller-এ পৌঁছানোর আগে middleware যাচাই করে —
> লগইন আছে কিনা, account active কিনা, role admin কিনা। শর্ত না মিললে request সেখানেই থেমে যায়।

### 2.5 Blade

**Q: What is Blade and how does it protect against XSS?**

**Answer:** Laravel's template engine. `{{ $value }}` HTML-escapes output (XSS protection); `{!! !!}` does not escape
(ResolveDesk never uses it for user data). Blade compiles to plain PHP and caches it.

**In ResolveDesk:** layout inheritance with `@extends('layouts.app')` / `@section('content')` / `@yield`; partials with
`@include('partials.ticket-badges', ['ticket' => $ticket])`; `@can('resolve', $ticket)` to show only allowed buttons;
`@csrf` and `@method('PUT')` in forms; `@json($stats['trend'])` to pass PHP arrays to Chart.js in
`dashboard/admin.blade.php`; `@push('scripts')` + `@stack('scripts')` for page-specific JS.

### 2.6 Dependency injection

**Q: What is dependency injection?**

**Answer:** A class receives the objects it needs (its dependencies) from outside instead of creating them with `new`.
This makes code easier to test and change.

**In ResolveDesk:** `TicketController` receives `TicketService` in its constructor; controller methods receive
`Request $request` and route models (`Ticket $ticket`) as parameters. Nobody writes `new TicketService()`.

### 2.7 Service container

**Q: What is the service container?**

**Answer:** Laravel's object factory. When a class asks for a type (constructor or method parameter), the container
builds it — and its dependencies — automatically using reflection. You can also bind interfaces or singletons.

**In ResolveDesk:** `TicketService` is not registered anywhere; the container builds it automatically because its
constructor has no required parameters. Tests ask the container directly:
`app(TicketService::class)->assignAgent($ticket, $agent, $admin);` (`QueueAndEmailTest`).

> **বাংলায়:** Service container একটি "স্বয়ংক্রিয় কারখানা"। Controller-এর constructor-এ `TicketService` লিখলেই
> Laravel নিজে object বানিয়ে দেয়। আমাদের `new` লিখতে হয় না, এবং test-এ সহজে fake/mock বসানো যায়।

### 2.8 Facades

**Q: What is a facade?**

**Answer:** A class with a static-looking interface (`Cache::forget()`) that forwards the call to a real object from the
service container. Convenient syntax, still swappable in tests.

**In ResolveDesk:** `DB::transaction()`, `Cache::remember()`, `Log::error()`, `Gate::authorize()`, `Auth::attempt()`,
`Mail::to()`, `RateLimiter::hit()`. Tests replace them: `Queue::fake()`, `Mail::fake()`, and
`Mail::shouldReceive('to')->andThrow(new Exception('SMTP server unavailable'))` in
`test_email_failure_does_not_reverse_the_committed_assignment`.

### 2.9 Authentication

**Q: How does login work?**

**Answer:** Session-based authentication: Laravel checks the password hash, stores the user id in the session and
sends a session cookie. Later requests are recognised by that cookie.

**In ResolveDesk:** `AuthController::login()`:
1. validate email + password;
2. `RateLimiter` — max 5 failed attempts per email+IP, 60-second lockout;
3. `Auth::attempt($credentials, $request->boolean('remember'))` — compares with the bcrypt hash;
4. inactive account → `Auth::logout()` + error;
5. `$request->session()->regenerate()` — new session id (prevents session fixation).

Passwords are hashed by the `'password' => 'hashed'` cast in `User::casts()`. There is no public registration —
admins create users.

### 2.10 Authorization

**Q: Authentication vs authorization? How is authorization layered here?**

**Answer:** Authentication = *who are you?* Authorization = *what may you do?*

**In ResolveDesk (four layers):**
1. Route middleware `role:admin` — whole admin areas.
2. `TicketPolicy` via `Gate::authorize()` in every ticket controller action — per-ticket rules.
3. Query scoping: `Ticket::visibleTo($user)` so lists never contain other people's tickets; notifications always start
   from `$request->user()->notifications()`.
4. Blade `@can` — **only** hides buttons. It is not security; the controller checks the same policy again.

### 2.11 Policies

**Q: What is a policy?**

**Answer:** A class with one method per action that returns `true`/`false` for "may this user do this to this model?".
Laravel finds it by naming convention.

**In ResolveDesk:** `App\Policies\TicketPolicy` (auto-discovered for `App\Models\Ticket`) has `view`, `create`,
`update`, `assign`, `start`, `resolve`, `close`, `reopen`, `comment`. Rules combine **role**, **ownership** and
**status**:

```php
    public function close(User $user, Ticket $ticket): bool
    {
        return ($user->isAdmin() || $this->isCreator($user, $ticket))
            && $ticket->canPerform('close');
    }
```

> **বাংলায়:** Policy হলো নির্দিষ্ট model-এর জন্য অনুমতির নিয়মের তালিকা। "এই user কি এই টিকিট close করতে পারবে?" —
> উত্তর `true` বা `false`। Controller-এ `Gate::authorize()` false পেলে 403 দেয়, আর Blade-এ `@can` একই নিয়ম দিয়ে
> বাটন লুকায়।

### 2.12 Validation

**Q: How do you validate input?**

**Answer:** `$request->validate([...rules])`. On failure Laravel redirects back with `$errors` and old input (or returns
422 JSON for AJAX). Only validated fields are returned.

**In ResolveDesk:** `TicketController::store()`:

```php
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'priority' => ['required', Rule::in(array_keys(Ticket::PRIORITIES))],
```

Other rules used: `Rule::unique('users', 'email')->ignore($user->id)`, `'confirmed'` (password), `'after_or_equal:date_from'`
(filters), `['nullable', 'date']` for `?since=` in `NotificationController::feed()`. Even URL filters are validated in
`TicketController::index()`. Business rules (e.g. "auto assign only for Open tickets") throw
`ValidationException::withMessages()` from `TicketService`.

### 2.13 Sessions

**Q: What is a session and where is it stored?**

**Answer:** Server-side data linked to a browser by a cookie. Used for the logged-in user, CSRF token, flash messages
and old form input.

**In ResolveDesk:** `SESSION_DRIVER=database` → the `sessions` table (created in the users migration). Flash messages:
`back()->with('success', '...')`, displayed once by `partials/flash.blade.php`. Logout calls
`$request->session()->invalidate()` and `regenerateToken()`. Tests use `SESSION_DRIVER=array` (`phpunit.xml`).

### 2.14 CSRF

**Q: What is CSRF and how does Laravel prevent it?**

**Answer:** Cross-Site Request Forgery: another website makes the victim's browser send a request (with the victim's
cookies). Laravel requires a secret per-session token on every POST/PUT/PATCH/DELETE; a foreign site cannot read it.
Missing/wrong token → **419**.

**In ResolveDesk:** `@csrf` in every form; `<meta name="csrf-token" content="{{ csrf_token() }}">` in
`layouts/app.blade.php`; `notifications.js` sends it as the `X-CSRF-TOKEN` header in `postJson()`. Custom page:
`resources/views/errors/419.blade.php`. Verified during the smoke test: a POST without token returned 419.

> **বাংলায়:** CSRF আক্রমণে অন্য একটি ওয়েবসাইট আপনার লগইন করা browser দিয়ে গোপনে request পাঠায়। Laravel প্রতিটি
> ফর্মে একটি গোপন token চায়, যা শুধু আমাদের পেজেই থাকে। Token না মিললে request বাতিল হয় (419)।

### 2.15 Route model binding

**Q: What is route model binding?**

**Answer:** If a route has `{ticket}` and the controller parameter is type-hinted `Ticket $ticket`, Laravel loads the
model by id automatically and returns **404** if it does not exist.

**In ResolveDesk:** `public function show(Request $request, Ticket $ticket)`. Binding only **finds** the ticket; it
does not check permission — that is `Gate::authorize('view', $ticket)` on the next line.
Notifications deliberately do **not** use binding: `markAsRead(Request $request, string $id)` does
`$request->user()->notifications()->findOrFail($id)`, so another user's notification id gives 404.

---

## Part 3 — Database and Eloquent

### 3.1 Migrations

**Q: What is a migration?**

**Answer:** Version control for the database schema: a PHP class with `up()` (apply) and `down()` (undo). Everyone
runs the same files with `php artisan migrate`.

**In ResolveDesk:** `database/migrations/` creates `users` + `sessions`, `cache`, `jobs` + `failed_jobs`,
`categories`, `tickets`, `ticket_comments`, `ticket_activities`, `notifications`. Example decisions inside the
tickets migration: `string('status', 20)` instead of an ENUM, `dateTime('due_at')` instead of `timestamp`, indexes and
foreign keys. Rule: never edit a migration that already ran on a shared database — add a new one
(`php artisan make:migration add_phone_to_users_table --table=users`).

### 3.2 Seeders

**Q: What is a seeder?**

**Answer:** A class that inserts initial or demo data (`php artisan db:seed`).

**In ResolveDesk:** `database/seeders/DatabaseSeeder.php` creates 1 admin, 4 agents, 5 customers (one inactive),
7 categories (one inactive) and 32 tickets with their full history (activities, comments, notifications) using
timestamps in the past, so dashboards and overdue badges look realistic. It sends no emails and clears both cache keys
at the end. `php artisan migrate:fresh --seed` **drops all tables first** — local only.

### 3.3 Factories

**Q: What is a model factory?**

**Answer:** A blueprint for generating fake model data, mainly for tests: `User::factory()->agent()->create()`.

**In ResolveDesk:** `TicketFactory` has states `assignedTo($agent)`, `inProgress($agent)`, `resolved($agent)`,
`closed($agent)` and a `configure()` hook that builds the ticket number after insert, exactly like the service:

```php
        return $this->afterCreating(function (Ticket $ticket) {
            $ticket->ticket_number = Ticket::makeTicketNumber($ticket->id);
            $ticket->save();
        });
```

`UserFactory` hashes the password once (`static::$password ??= Hash::make('password')`) to keep tests fast.

### 3.4 Models

**Q: What does an Eloquent model contain?**

**Answer:** The mapping to one table: mass-assignment rules, casts, relationships, helper methods and query scopes.

**In ResolveDesk:** `app/Models/Ticket.php` holds all lifecycle constants (`STATUSES`, `PRIORITIES`, `SLA_HOURS`,
`TRANSITIONS`, `ACTIVE_STATUSES`, `WORKLOAD_STATUSES`), casts (`due_at` → Carbon, foreign keys → integer),
relationships (`creator`, `assignedAgent`, `category`, `comments`, `activities`), helpers (`calculateDueAt()`,
`makeTicketNumber()`, `canPerform()`, `isOverdue()`) and scopes (`overdue()`, `visibleTo()`).

### 3.5 Mass assignment

**Q: What is mass assignment and how is it protected?**

**Answer:** Filling many attributes from an array (`create($data)`, `fill($data)`, `update($data)`). If the array
comes from user input, an attacker could add extra fields (e.g. `role=admin`). `$fillable` lists the only columns
that may be filled this way; others are ignored.

**In ResolveDesk:**

```php
    protected $fillable = [
        'subject',
        'description',
        'category_id',
        'priority',
    ];
```

`status`, `created_by`, `assigned_to`, `ticket_number`, `due_at`, `resolved_at` and `resolution_note` are **not**
fillable; `TicketService` sets them explicitly (`$ticket->status = Ticket::STATUS_OPEN;`). The test
`test_customer_can_create_a_ticket` posts `'created_by' => $someoneElse->id` and `'status' => 'closed'` and asserts
both were ignored. (Double protection: the controller also passes only `$validated` data.)

> **বাংলায়:** Mass assignment মানে একসাথে array থেকে অনেক কলাম ভরা। ব্যবহারকারী ফর্মে বাড়তি field (যেমন
> `status=closed`) পাঠাতে পারে। `$fillable`-এ যে কলাম নেই, Laravel সেটি উপেক্ষা করে — তাই `status` কখনো ফর্ম থেকে
> বদলানো যায় না, শুধু `TicketService` বদলায়।

### 3.6 Relationships

**Q: Which relationship types do you use?**

**Answer:** `belongsTo` (this table has the foreign key), `hasMany` (the other table has it), and polymorphic
`morphMany` (one table points to many model types with `*_type` + `*_id`).

**In ResolveDesk:**

```php
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');   // custom foreign key name
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('created_at')->orderBy('id');
    }
```

`User` has `createdTickets()` and `assignedTickets()` — two `hasMany` to the same table with different foreign keys.
Notifications are polymorphic: the migration uses `$table->morphs('notifiable')` (`notifiable_type`,
`notifiable_id`) and the `Notifiable` trait provides `$user->notifications()`.

> **বাংলায়:** Polymorphic relationship-এ একটি টেবিল (`notifications`) বিভিন্ন ধরনের model-এর সাথে যুক্ত হতে পারে।
> `notifiable_type` কলামে model-এর class নাম (যেমন `App\Models\User`) এবং `notifiable_id` কলামে তার id থাকে।

### 3.7 Query Builder

**Q: What is the Query Builder?**

**Answer:** A fluent PHP API that builds SQL with bound parameters (`DB::table('jobs')->count()`). Eloquent is built on
top of it; you can mix both, e.g. raw expressions inside an Eloquent query.

**In ResolveDesk:** `DashboardController::buildAdminStatistics()`:

```php
        // SELECT status, COUNT(*) AS total FROM tickets GROUP BY status
        $countsByStatus = Ticket::select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status');
```

`DB::raw()` inserts SQL as-is — only for fixed expressions, never for user input. Tests use the builder directly:
`DB::table('jobs')->value('payload')`.

### 3.8 Eloquent

**Q: What is Eloquent?**

**Answer:** Laravel's ORM (Active Record): one class per table, one object per row, relationships as methods,
`save()`/`update()` to persist.

**In ResolveDesk:** `Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail()` (`TicketService::lockTicket()`),
`$request->user()->unreadNotifications()->update(['read_at' => now()])` (one `UPDATE` for all rows in
`NotificationController::markAllAsRead()`), `$category->tickets()->exists()` before deleting a category.

### 3.9 Eager loading

**Q: What is eager loading?**

**Answer:** Loading relationships **up front** for all models in a few queries (`with()`), instead of one query per
model when the relationship is accessed.

**In ResolveDesk:**

```php
        $query = Ticket::with(['category', 'creator', 'assignedAgent'])
            ->visibleTo($user);                                         // TicketController::index()

        $ticket->load(['category', 'creator', 'assignedAgent', 'comments.user', 'activities.user']); // show()
```

15 tickets per page = 1 query for tickets + 3 for the relationships, whatever the page size. `withCount(...)` is used
for counts (agent workload, `tickets_count` on categories).

### 3.10 Lazy loading

**Q: What is lazy loading?**

**Answer:** The relationship is loaded the first time you access the property (`$ticket->creator`), with a new query.
Fine for one model, expensive inside loops.

**In ResolveDesk:** lazy loading is used deliberately on **single** locked rows, e.g. `$lockedTicket->creator` in
`TicketService::resolve()`. For lists it is blocked in development by `Model::preventLazyLoading()` (see 5.7).

### 3.11 N+1 queries

**Q: What is the N+1 problem?**

**Answer:** 1 query loads N rows, then each row triggers another query for a relationship → N+1 queries. Fix: eager
loading or `withCount`.

**In ResolveDesk:** without `with(['category', 'creator', 'assignedAgent'])`, the ticket list would run
1 + 3×15 = 46 queries per page instead of 4. `AppServiceProvider` turns a forgotten eager load into an exception outside
production. See [exercise 28](08-LIVE-CODING-PRACTICE.md#solution-28--fix-an-n1-query-i).

> **বাংলায়:** N+1 সমস্যা: ১টি query দিয়ে ১৫টি টিকিট আনা হলো, তারপর প্রতিটি টিকিটের creator আনতে আরও ১৫টি query।
> `with()` ব্যবহার করলে সব creator একটি query-তেই চলে আসে (`WHERE id IN (...)`)। ফলে query-এর সংখ্যা ডেটার
> পরিমাণের উপর নির্ভর করে না।

### 3.12 Pagination

**Q: How does pagination work?**

**Answer:** `paginate(15)` runs a `COUNT(*)` plus a `LIMIT/OFFSET` query and returns a paginator that renders links.

**In ResolveDesk:** `$query->latest()->paginate(self::PER_PAGE)->withQueryString()` — `withQueryString()` keeps the
filters in page links. `Paginator::useBootstrapFive()` in `AppServiceProvider` makes `{{ $tickets->links() }}` output
Bootstrap 5 HTML.

**Follow-up: problem with OFFSET at page 10 000?** The database still reads and skips all earlier rows. For very large
tables, cursor pagination (`cursorPaginate()`, `WHERE id < last_seen_id`) is faster — not needed at this project's size.

### 3.13 Transactions

**Q: What is a database transaction?**

**Answer:** A group of statements that succeed together (COMMIT) or are all undone (ROLLBACK). Guarantees atomicity.

**In ResolveDesk:** every `TicketService` method wraps its work in `DB::transaction()`. Example: assignment updates
`tickets`, inserts two `ticket_activities` rows and one `notifications` row; if the notification insert failed, the
assignment is rolled back too. `QueueAndEmailTest::test_no_email_job_is_dispatched_when_the_transaction_rolls_back`
proves nothing is left behind (no notification, no activity, no job).

> **বাংলায়:** Transaction মানে "সব নয়তো কিছুই না"। টিকিট assign করতে কয়েকটি টেবিলে লেখা হয়; মাঝপথে error হলে
> আগের সব পরিবর্তন বাতিল (rollback) হয়ে যায়, ফলে ডেটা কখনো অর্ধেক-সম্পূর্ণ অবস্থায় থাকে না।

### 3.14 Indexes

**Q: What is an index and which ones did you add?**

**Answer:** A sorted lookup structure that lets the database find rows without scanning the whole table. It speeds up
`WHERE`, `ORDER BY` and joins, but costs disk space and slows writes slightly.

**In ResolveDesk (tickets migration):** `status`, `priority`, `due_at`, `created_at`, composite
`['assigned_to', 'status']` for the workload query, unique `ticket_number`; unique `users.email`, unique
`categories.name`, `users.role`. Foreign key columns are indexed by MySQL/MariaDB automatically.

**Follow-up: why is `LIKE '%printer%'` slow even with an index?** A leading wildcard cannot use a B-tree index;
it needs a full-text index or a search engine.

### 3.15 Foreign keys

**Q: What do foreign keys give you?**

**Answer:** Referential integrity enforced by the database: a ticket cannot point to a non-existing user or category,
and delete behaviour is defined (`restrict`, `cascade`, `set null`).

**In ResolveDesk:**

```php
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
```

Users and used categories can never be deleted (history must survive) — the app deactivates them instead, and
`CategoryController::destroy()` checks `$category->tickets()->exists()` first to show a friendly message.
`ticket_comments` and `ticket_activities` use `cascadeOnDelete()` (only relevant if a developer deletes a ticket
manually; the UI has no delete route).

---

## Part 4 — System design

### 4.1 Monolithic architecture

**Q: Is this a monolith? Why?**

**Answer:** Yes: one Laravel codebase and one database serve the web pages, the JSON endpoints and the queue worker.
For a small team this is simpler to build, test, deploy and debug than microservices.

**In ResolveDesk:** the same app handles `/tickets`, `/notifications/feed` (JSON for polling) and
`php artisan queue:work` (same code, separate process). Clear internal boundaries (controllers → `TicketService` →
models) keep it modular inside.

### 4.2 Cache

**Q: Where do you cache and how do you keep it correct?**

**Answer:** Cache-aside: read from cache; on a miss compute, store with a TTL, return. Invalidate when the source data
changes.

**In ResolveDesk:** store = `database` (`cache` table), two keys:
- `dashboard:admin:stats` — `DashboardController::adminDashboard()` with `Cache::remember(..., 60, ...)`; cleared by
  `TicketService::clearDashboardCache()` after every ticket change and by `CategoryController::clearCaches()`.
- `ticket:active-categories` — `Category::activeForDropdown()` (TTL 3600); cleared by `Category::clearActiveCache()`.

Deliberately **not** cached: overdue counts, permissions, the category check when saving a ticket (it queries the
database: `Rule::exists('categories', 'id')->where('is_active', true)`).

> **বাংলায়:** Cache-aside মানে: আগে ক্যাশে খুঁজি; না পেলে ডাটাবেস থেকে হিসাব করে ক্যাশে রেখে দিই। ডেটা বদলালে
> ক্যাশের key মুছে দিই, যাতে পরের request নতুন করে হিসাব করে। যে তথ্য সবসময় সঠিক হতে হবে (অনুমতি, overdue),
> তা ক্যাশ করা হয় না।

### 4.3 Queue

**Q: What does the queue do here?**

**Answer:** It stores slow work (sending email) as jobs to run later in a separate worker process, so the web request
stays fast and survives mail-server problems.

**In ResolveDesk:** `QUEUE_CONNECTION=database` → `jobs` and `failed_jobs` tables. One job class:
`App\Jobs\SendTicketEmail` (`$tries = 3`, `$backoff = 10`, `failed()` logs). Start a worker with
`php artisan queue:work`.

### 4.4 Asynchronous processing

**Q: What runs synchronously and what asynchronously?**

**Answer:** Synchronous = inside the HTTP request; asynchronous = later, in another process.

**In ResolveDesk:** synchronous: ticket changes, activities and **in-app notifications** (database channel, same
transaction — they work even with no worker). Asynchronous: **emails** (queued, sent by the worker). Verified: with no
worker, 2 jobs waited in `jobs`; `queue:work --stop-when-empty` sent both to the mail log.

### 4.5 Producer–consumer pattern

**Q: Explain producer–consumer with your code.**

**Answer:** Producers put messages into a buffer; consumers take them out and process them, independently and at their
own speed.

**In ResolveDesk:** producer = `TicketService::queueEmail()` → `SendTicketEmail::dispatch(...)` inserts a row into
`jobs`; buffer = the `jobs` table; consumer = `php artisan queue:work` calling `SendTicketEmail::handle()`. You can run
several workers (consumers) for more throughput.

> **বাংলায়:** Producer কাজ তৈরি করে তালিকায় রাখে (web request → `jobs` টেবিল), consumer সেই তালিকা থেকে কাজ তুলে
> সম্পন্ন করে (queue worker → ইমেইল)। দুজন আলাদা গতিতে কাজ করতে পারে; worker বন্ধ থাকলেও কাজ হারায় না।

### 4.6 Polling

**Q: How do near-real-time notifications work?**

**Answer:** The browser asks the server for new data at a fixed interval (AJAX polling).

**In ResolveDesk:** `public/js/notifications.js` calls `GET /notifications/feed` every 5 s (30 s when the tab is hidden)
with a recursive `setTimeout` (no overlapping requests). It sends `?since=<server_time>` from the previous response;
the server returns the unread count, the latest 5 and every new unread notification since that time. Toasts are
de-duplicated with `shownToastIds` (a `Set`). On 401/419 it stops and shows "Sign in again".

### 4.7 WebSockets

**Q: What are WebSockets and do you use them?**

**Answer:** A persistent two-way connection, so the server can **push** a message instantly. Laravel supports this via
broadcasting (Reverb, Pusher). **Not implemented** in ResolveDesk.

**In ResolveDesk:** polling was chosen because it needs no extra server process, works on any shared host/XAMPP and is
easy to debug; a delay of a few seconds is acceptable for ticket notifications. Moving to WebSockets later would mean:
a broadcasting server, a `ShouldBroadcast` notification channel, private channel authorization, and JS listening
instead of polling. See 5.5.

### 4.8 Load balancing

**Q: How would you put this behind a load balancer?**

**Answer:** A load balancer spreads requests over several identical app servers. That works only if no server keeps
important state locally. **Not implemented** — this is a design discussion.

**In ResolveDesk:** it is already fairly ready: sessions (`SESSION_DRIVER=database`), cache (`CACHE_STORE=database`)
and the queue (`QUEUE_CONNECTION=database`) all live in the shared database, so any server can handle any request.
Remaining items: shared `APP_KEY`, central logs instead of `storage/logs/laravel.log` per server, and file storage if
uploads were added.

### 4.9 Horizontal scaling

**Q: Vertical vs horizontal scaling?**

**Answer:** Vertical = a bigger server; horizontal = more servers. Horizontal needs stateless app servers.

**In ResolveDesk:** web servers and queue workers can be multiplied (workers safely share the `jobs` table — a job is
reserved by one worker). The bottleneck then becomes the single database (sessions, cache, queue, polling queries all
hit it). Future steps: Redis for cache/sessions/queue, read replicas for reports, and WebSockets to remove polling load.

### 4.10 Database optimization

**Q: What did you do for database performance?**

**Answer / In ResolveDesk:**
- Eager loading (`with`, `load`) and `withCount` → no N+1; enforced by `preventLazyLoading`.
- Indexes on filter/sort columns, composite `['assigned_to', 'status']`.
- Aggregates in SQL (`GROUP BY`, `COUNT`, `AVG(TIMESTAMPDIFF(...))`) instead of loading rows into PHP.
- Selecting only needed columns: `Category::where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray()`
  (cached as a plain array, turned back into models with `Category::hydrate()`).
- Pagination everywhere; dashboard lists use `take(8)`.
- Caching the expensive admin statistics for 60 s.
- One `UPDATE` for "mark all as read" instead of a loop.

### 4.11 Retry

**Q: How are failures retried?**

**Answer:** Transient failures (network, mail server) are retried a limited number of times with a delay; permanent
failures are recorded for manual action.

**In ResolveDesk:** `SendTicketEmail` — `$tries = 3`, `$backoff = 10` seconds, then `failed()` logs
"Ticket email failed permanently" and the job is stored in `failed_jobs` → `php artisan queue:retry all` after fixing
the cause. In the browser, `notifications.js` catches errors and simply schedules the next poll (the `finally` block).

### 4.12 Idempotency

**Q: What is idempotency? Where does it matter here?**

**Answer:** An operation is idempotent if doing it twice has the same effect as doing it once. Important wherever
retries or double-clicks happen.

**In ResolveDesk:**
- Assigning the same agent again returns `false` in `performAssignment()` → message "already assigned", **no** duplicate
  activity, notification or email (`test_assigning_the_same_agent_again_creates_no_duplicates`).
- Transitions check the current status after locking, so a double-clicked "Resolve" fails the second time instead of
  resolving twice.
- `markAsRead` / `markAllAsRead` are naturally idempotent.
- Toasts: `shownToastIds` prevents showing one notification twice.
- **Not** idempotent: sending an email. A retry after an unclear SMTP failure can send a duplicate — accepted for
  notification emails.

> **বাংলায়:** Idempotent কাজ একবার করলে যা হয়, দুবার করলেও তাই হয়। একই agent-কে আবার assign করলে ResolveDesk কিছুই
> বদলায় না — নতুন activity বা notification তৈরি হয় না। কিন্তু ইমেইল পাঠানো idempotent নয়; retry হলে ডুপ্লিকেট
> ইমেইল যেতে পারে।

### 4.13 Data consistency

**Q: How do you keep data consistent?**

**Answer / In ResolveDesk:**
- **Atomicity:** `DB::transaction()` around every multi-table change.
- **Concurrency:** `lockTicket()` = `SELECT ... FOR UPDATE`, then the rule is checked again on the locked row.
- **Side effects after commit:** emails via `DB::afterCommit()`; dashboard cache cleared after the transaction.
- **Database constraints:** foreign keys, unique `ticket_number`, unique emails/category names.
- **No stored derived data:** "overdue" is calculated live from `due_at` and `status`.
- **Validate against the source of truth:** active category is checked in the database, not in the cached list.

---

## Part 5 — Tricky follow-up questions

### 5.1 "Why not generate the ticket number with `MAX(id) + 1`?"

**Answer:** Two requests running at the same time can both read the same `MAX(id)` and both produce `TK-000042` —
a race condition (the second insert then fails on the unique index, or worse, the numbers are wrong).

**In ResolveDesk:** `TicketService::createTicket()` first INSERTs (the database hands out a unique auto-increment id),
then builds the number from that id, inside the same transaction:

```php
            $ticket->save();                               // INSERT -> the database generates the id.

            // Ticket number is built from the database id, so it is always unique
            // (unlike MAX(id) + 1, which two requests could calculate at the same time).
            $ticket->ticket_number = Ticket::makeTicketNumber($ticket->id);
            $ticket->save();                               // UPDATE
```

`ticket_number` is `nullable()->unique()`: it is NULL only for a moment inside the transaction (a unique index allows
several NULLs), and the unique index is the final safety net.

### 5.2 "Why `DB::afterCommit()` for the email?"

**Answer:** If the job were dispatched inside the transaction and the transaction later rolled back, the email would
describe a change that never happened. Also, a fast worker could pick up the job **before** the commit and not see the
data yet.

**In ResolveDesk:** `TicketService::queueEmail()` registers the dispatch with `DB::afterCommit()`. On rollback the
callback is discarded → no job (`test_no_email_job_is_dispatched_when_the_transaction_rolls_back`). The dispatch is
wrapped in `try/catch (Throwable)` + `Log::error()`: the ticket change is already committed, so a queue failure must not
show the user a false error (`test_email_failure_does_not_reverse_the_committed_assignment`). Alternatives Laravel
offers: `->afterCommit()` on the dispatch, or `'after_commit' => true` in `config/queue.php` (here it is `false`;
the explicit call makes the intent visible).

> **বাংলায়:** `DB::afterCommit()` বলে "transaction সফলভাবে commit হলে তবেই এই কাজটি করো"। Rollback হলে কাজটি বাতিল
> হয়ে যায়। তাই কখনো এমন ইমেইল যায় না যার পেছনের ডেটা আসলে সংরক্ষিত হয়নি।

### 5.3 "What if two admins assign the same ticket at the same time?"

**Answer:** Both requests enter `TicketService::assignAgent()`. Each opens a transaction and calls `lockTicket()`:

```php
        return Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
```

Admin A gets the row lock first. Admin B's `SELECT ... FOR UPDATE` **waits** until A commits, then reads the **fresh**
row (status Assigned, `assigned_to` = A's agent):
- B chose the same agent → `performAssignment()` returns `false` → "already assigned", nothing duplicated.
- B chose a different agent → it becomes a normal **reassignment** (activity `reassigned`, the new agent is notified).
- B clicked *Auto Assign* → the status is no longer Open → `ValidationException` "Auto assign is only available for
  Open tickets".

There is never a lost update or two "assigned" activities. Note the policy check in the controller used the
(possibly stale) route-bound model — that is why the service re-checks the rules on the locked row.

> **বাংলায়:** `lockForUpdate()` টিকিটের row-টিকে transaction শেষ না হওয়া পর্যন্ত তালাবদ্ধ রাখে। দ্বিতীয় admin-এর
> request অপেক্ষা করে, তারপর আপডেট হওয়া ডেটা পড়ে সিদ্ধান্ত নেয়। ফলে দুজন একসাথে বদলালেও ডেটা নষ্ট হয় না।

### 5.4 "Why is 'overdue' not stored in a column?"

**Answer:** Overdue depends on **time**: a ticket becomes overdue at `due_at` without any request happening. A stored
flag would need a scheduler to update it and would be wrong between runs.

**In ResolveDesk:** it is calculated live, in PHP and SQL, from the stored `due_at` and the status:

```php
    public function isOverdue(): bool
    {
        if (! in_array($this->status, self::ACTIVE_STATUSES, true)) {
            return false;
        }

        return now()->greaterThan($this->due_at);
    }

    /** SQL: WHERE status IN ('open','assigned','in_progress') AND due_at < NOW() */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES)->where('due_at', '<', now());
    }
```

`due_at` is indexed, so the scope stays fast. The dashboard's overdue count is also **not cached** for the same reason.
(Scheduled escalation emails for overdue tickets would be a future feature.)

### 5.5 "Why polling and not WebSockets?"

**Answer:** Trade-off: polling is simple (normal HTTP routes, sessions, CSRF, policies), needs no extra server and runs
on XAMPP; the cost is a few seconds of delay and repeated requests. WebSockets give instant push but need a separate
always-on server (e.g. Laravel Reverb), channel authorization and more operational work.

**In ResolveDesk:** polling every 5 s (30 s when hidden) with `?since=` so no notification is missed, overlap prevention
(`isRequestRunning`, recursive `setTimeout`), toast de-duplication by ID, and stop on 401/419. Load estimate for the
interview: 500 active tabs ÷ 5 s = 100 small requests/second — at that point WebSockets (not implemented) become worth it.

### 5.6 "Why DATETIME instead of TIMESTAMP for `due_at` and `resolved_at`?"

**Answer:** On MariaDB (and MySQL with `explicit_defaults_for_timestamp` off), the **first NOT NULL `TIMESTAMP`** column
of a table silently gets `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. `due_at` was that column, so every
UPDATE of a ticket (assign, start, resolve...) overwrote the SLA deadline with "now". This bug was found
while seeding. `DATETIME` has no automatic behaviour, so it was changed:

```php
            // DATETIME (not TIMESTAMP) on purpose: on MariaDB / older MySQL, the first
            // NOT NULL TIMESTAMP column silently gets "ON UPDATE CURRENT_TIMESTAMP",
            // which would overwrite the SLA deadline on every UPDATE.
            $table->dateTime('due_at');                  // SLA deadline = created_at + priority hours
            $table->dateTime('resolved_at')->nullable(); // Set when resolved, cleared when reopened
```

Laravel's own `created_at`/`updated_at` (from `timestamps()`) are nullable, so they are not affected. Other
differences: `TIMESTAMP` is stored in UTC and limited to 2038; `DATETIME` stores the value as given (the app uses one
timezone, `APP_TIMEZONE=Asia/Dhaka`).

> **বাংলায়:** MariaDB-তে টেবিলের প্রথম NOT NULL TIMESTAMP কলাম নিজে নিজে "UPDATE হলে বর্তমান সময় বসাও" নিয়ম পায়।
> ফলে প্রতিবার টিকিট আপডেটে SLA deadline বদলে যাচ্ছিল। DATETIME-এ এমন কোনো স্বয়ংক্রিয় নিয়ম নেই, তাই deadline
> স্থির থাকে।

### 5.7 "What does `preventLazyLoading` do?"

**Answer:** It makes Eloquent throw a `LazyLoadingViolationException` when code accesses a relationship that was not
eager loaded — but only for models that were loaded **as part of a multi-row result** (exactly the N+1 situation). A
single model loaded with `find()`/`firstOrFail()` may still lazy load.

**In ResolveDesk:**

```php
        // Outside production, throw an error when a relationship is lazy-loaded
        // inside a loop (the N+1 query problem). This forces us to use with().
        Model::preventLazyLoading(! $this->app->isProduction());
```

So in local development and in tests a forgotten `with()` crashes immediately (you notice it), while in production
users get a slower page instead of an error. That is why `TicketService` can safely use `$lockedTicket->creator` on a
single locked row.

### 5.8 "Why is `status` not in `$fillable`?"

**Answer:** Status may only change through the lifecycle rules (policy + `Ticket::TRANSITIONS` + lock). If it were
fillable, any code path that does `$ticket->update($request->all())` could jump a ticket from Open to Closed.

**In ResolveDesk:** status is only set explicitly in `TicketService` (`$lockedTicket->status = Ticket::STATUS_RESOLVED;`).
The edit form never sends status, `updateTicket()` documents "Status is never changed here", and the creation test
proves a posted `status=closed` is ignored.

### 5.9 "If the policy already checked the status, why does the service check it again?"

**Answer:** Time passes between the two checks. The policy ran on the model loaded at the start of the request; another
user may have changed the ticket since. The service re-reads the row **with a lock** and calls
`ensureActionAllowed()`, which is the authoritative check. The policy check is still needed for *who* may act (and gives
a clean 403); the service check guarantees the *state* is still valid.

### 5.10 "Why both `role:admin` middleware and the policy?"

**Answer:** Defence in depth and clarity. Middleware protects whole areas cheaply (users, categories, assignment routes)
before any controller code runs. The policy expresses per-ticket rules that depend on ownership and status. Blade
`@can` only hides buttons — `AuthorizationTest::test_non_admin_cannot_assign_by_posting_directly` proves a hand-crafted
POST is still blocked.

### 5.11 "Why are in-app notifications not queued, but emails are?"

**Answer:** The in-app notification is a single fast INSERT that must be consistent with the ticket change, so it is
created inside the same transaction (rollback removes it too) and works even when no worker runs. Email talks to an
external server — slow and unreliable — so it is queued, retried and sent after commit.

**In ResolveDesk:** `TicketNotification::via()` returns `['database']` and the class does not implement `ShouldQueue`.

### 5.12 "Why does the email job receive plain strings and an array instead of the Ticket model?"

**Answer:** The job payload stays small and describes the event **at the time it happened** (e.g. status "Assigned"),
even if the ticket changes again before the worker runs. It also avoids a database read in the worker and problems if
the model changes shape.

**In ResolveDesk:** `queueEmail()` copies `ticket_id`, `ticket_number`, `subject`, `status`, `event_title`,
`event_message` into `$emailData`; `SendTicketEmail::__construct(public string $recipientEmail, public string $recipientName, public array $emailData)`.

### 5.13 "What happens if the queue worker is down?"

**Answer:** Nothing is lost and users see no error: ticket changes and in-app notifications are committed; email jobs
wait in the `jobs` table and are sent when a worker starts (`test_in_app_notification_works_without_a_queue_worker`).

### 5.14 "Why do business-rule failures throw `ValidationException`?"

**Answer:** Laravel already knows how to present it: a normal form request redirects back with the message in `$errors`
and keeps old input; an AJAX request gets a 422 JSON response. No custom exception handler is needed, and the error
appears in the same flash box as validation errors (`partials/flash.blade.php`).

### 5.15 "Why string columns + PHP constants instead of a database ENUM?"

**Answer:** Adding a value (a new priority or role) is a code change only — no `ALTER TABLE` on a large table. Validation
(`Rule::in(array_keys(Ticket::PRIORITIES))`), dropdowns and labels all read the same constant, so the list exists in
one place. The trade-off: the database itself does not reject invalid values, so all writes must go through validated
code (they do).

### 5.16 "Why does the browser send `?since=` with the **server's** time?"

**Answer:** The user's computer clock may be wrong or in another timezone. The server returns `server_time`
(`now()->toIso8601String()`, captured before the queries); the browser sends it back unchanged on the next poll, and
the server returns unread notifications with `created_at >= since` minus a 10-second overlap
(`SINCE_OVERLAP_SECONDS`, so a row from a transaction that committed late still gets a toast). Clock differences can
never hide a notification; the `>=` and the overlap may return a row twice, which the `shownToastIds` Set handles.

### 5.17 "Why is the dashboard cache cleared **after** the transaction and not inside it?"

**Answer:** If the key were deleted inside the transaction, another request could rebuild the cache **before** the commit,
reading the old data, and keep it for 60 s. Clearing after the commit guarantees the next rebuild sees the new data.

**In ResolveDesk:** every service method ends with `$this->clearDashboardCache();` after `DB::transaction(...)` returns.

### 5.18 "What happens to a user who is deactivated while logged in?"

**Answer:** `ActiveUserMiddleware` (alias `active`) runs on every protected request, sees `is_active = false`, logs the
user out, invalidates the session and redirects to login (or returns 401 JSON for polling, which makes
`notifications.js` show "Sign in again").

### 5.19 "Why `$request->session()->regenerate()` after login?"

**Answer:** It prevents **session fixation**: an attacker who planted a known session id before login cannot reuse it,
because the id changes at the moment of authentication.

### 5.20 "Why the integer casts on `created_by` / `assigned_to`?"

**Answer:** The policy compares with strict equality (`$ticket->assigned_to === $user->id`). Some database drivers
return numbers as strings; without the cast `"5" === 5` is `false` and the real assigned agent would get a 403.

### 5.21 "What are the known limitations / what would you improve?"

Be honest — interviewers like this:
- `TicketService::updateTicket()` does not lock the ticket like the transition methods do, so an edit racing with an
  assignment is possible (low impact: `save()` writes only the changed columns, never status or assignee).
- SLA uses continuous calendar hours (no business hours, no pause while waiting for the customer).
- Polling costs requests; WebSockets would scale better (not implemented).
- Emails are not idempotent (a retry can duplicate an email).
- Developed and verified on MariaDB 10.4 (XAMPP), while the target is MySQL 8; the only DB-specific SQL is
  `TIMESTAMPDIFF` and `DATE()` in `DashboardController`, which both support.
- Not verified: rendering in a real browser, the JS toast in a browser, real Gmail SMTP delivery (see
  `DEVELOPMENT_PROGRESS.md`).

---

## Quick self-test (answer out loud in under a minute each)

1. Walk through what happens when an admin clicks *Assign* — route, middleware, policy, validation, service, lock,
   activity, notification, email, cache.
2. Where are the allowed statuses defined, and name four places that read them.
3. Why can a customer not set `status=closed` when creating a ticket? Give two reasons.
4. Show the eager loading in `TicketController::index()` and explain the query count.
5. Explain `Cache::remember()` and name both cache keys and where each is cleared.
6. What is in the `jobs` table after an assignment when no worker runs? What command sends it?
7. Explain 403 vs 404 vs 419 vs 422 with an example from this project each.
8. What does `lockForUpdate()` protect against? Give the two-admins scenario.
9. Why is overdue computed and not stored?
10. Why DATETIME for `due_at`?
