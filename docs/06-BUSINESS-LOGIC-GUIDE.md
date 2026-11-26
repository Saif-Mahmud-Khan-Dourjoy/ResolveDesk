# 06 — Business Logic Guide

This guide explains **every business rule** in ResolveDesk: what the rule is, where the code lives, what
happens in the database, and how to change it during an interview.

Related documents:
[UI to backend mapping](07-UI-TO-BACKEND-MAPPING.md) ·
[Database guide](03-DATABASE-GUIDE.md) ·
[Notification system](13-NOTIFICATION-SYSTEM-GUIDE.md) ·
[Queue and email](14-QUEUE-AND-EMAIL-GUIDE.md) ·
[Caching](15-CACHING-GUIDE.md)

---

## How to read this guide

Each important rule uses the same 13 items (required by `MASTER_PROMPT.md` §20):

| # | Item | Meaning |
|---|---|---|
| 1 | Business requirement | What the business wants |
| 2 | Realistic scenario | A real-life story |
| 3 | Input data | What the user sends |
| 4 | Expected result | What should happen |
| 5 | Actual implementation | File + class + method |
| 6 | Code explanation | The real code, explained |
| 7 | DB state before | Small table before |
| 8 | DB state after | Small table after |
| 9 | Equivalent SQL | Roughly what Eloquent runs |
| 10 | Edge cases | Unusual situations |
| 11 | Error handling | The exact message from the code |
| 12 | Interview questions | Questions you may be asked |
| 13 | How to modify | Where and how to change the rule |

The example dates use the app timezone `Asia/Dhaka` (`APP_TIMEZONE` in `.env`).

### Where business rules live (one-minute map)

| Kind of rule | Location |
|---|---|
| Allowed values, SLA hours, transitions, overdue | `app/Models/Ticket.php` (constants + helper methods + scopes) |
| Coordinated ticket operations | `app/Services/TicketService.php` |
| WHO may do an action on a ticket | `app/Policies/TicketPolicy.php` |
| Role-only pages (admin) | `app/Http/Middleware/RoleMiddleware.php` via `role:admin` in `routes/web.php` |
| Inactive users blocked | `app/Http/Controllers/AuthController.php::login()` + `app/Http/Middleware/ActiveUserMiddleware.php` |
| User rules (self, last admin, agent with tickets) | `app/Http/Controllers/UserController.php::ensureRoleOrStatusChangeIsAllowed()` |
| Category rules | `app/Http/Controllers/CategoryController.php` + `app/Models/Category.php` |
| Dashboard numbers | `app/Http/Controllers/DashboardController.php` |
| In-app notification payload | `app/Notifications/TicketNotification.php` |
| Email job | `app/Jobs/SendTicketEmail.php` |

### How a business-rule failure is reported

Business-rule failures throw `Illuminate\Validation\ValidationException`:

```php
throw ValidationException::withMessages([
    'ticket' => 'Auto assign is only available for Open tickets. Use Reassign instead.',
]);
```

- Normal form submit → Laravel redirects **back** and puts the message in `$errors`.
  `resources/views/partials/flash.blade.php` shows it in a red alert ("Please fix the following:").
- AJAX request (`Accept: application/json`) → HTTP **422** with JSON errors.
- Because the exception is thrown **inside** `DB::transaction()`, the transaction is rolled back first.

Permission failures are different: `Gate::authorize()` throws `AuthorizationException` → HTTP **403** →
`resources/views/errors/403.blade.php` ("Access denied").

> **বাংলায়:** দুই ধরনের "না" আছে। "তুমি এটা করার অনুমতি পাওনি" (যেমন customer অন্য কারো ticket খুলছে) — এটা
> 403, Policy থেকে আসে। আর "তুমি অনুমতি পেয়েছ, কিন্তু এখন এই কাজটা নিয়ম অনুযায়ী সম্ভব না" (যেমন resolution note
> খালি) — এটা ValidationException, ফর্মে লাল error message হিসেবে ফেরত যায়। Interview-এ এই পার্থক্যটা বলতে পারলে ভালো।

---

## Table of contents

1. [User activation and deactivation](#1-user-activation-and-deactivation)
2. [Category management](#2-category-management)
3. [Ticket creation](#3-ticket-creation)
4. [Ticket number generation](#4-ticket-number-generation)
5. [Manual assignment](#5-manual-assignment)
6. [Automatic assignment](#6-automatic-assignment)
7. [Reassignment](#7-reassignment)
8. [Workload calculation](#8-workload-calculation)
9. [Status transitions](#9-status-transitions)
10. [Resolution](#10-resolution)
11. [Reopening](#11-reopening)
12. [Closure](#12-closure)
13. [SLA calculation (and priority change)](#13-sla-calculation-and-priority-change)
14. [Overdue calculation](#14-overdue-calculation)
15. [Comments and the recipient rule](#15-comments-and-the-recipient-rule)
16. [Activity logging](#16-activity-logging)
17. [Dashboard statistics](#17-dashboard-statistics)
18. [Database transactions and row locking](#18-database-transactions-and-row-locking)
19. [Notifications (matrix as implemented)](#19-notifications-matrix-as-implemented)
20. [Email job dispatch after commit](#20-email-job-dispatch-after-commit)
21. [Cache invalidation](#21-cache-invalidation)
22. [Permission checks](#22-permission-checks)
23. [Known limitations and future improvements](#23-known-limitations-and-future-improvements)

---

## 1. User activation and deactivation

There are four related rules:

| Rule | Where |
|---|---|
| A. Inactive users cannot log in | `AuthController::login()` |
| B. A user deactivated **during** a session is logged out on the next request | `ActiveUserMiddleware::handle()` |
| C. Admin cannot deactivate/demote themselves; the last active admin is protected | `UserController::ensureRoleOrStatusChangeIsAllowed()` rules 1 and 2 |
| D. An agent responsible for Assigned / In Progress / Resolved tickets cannot be deactivated or have their role changed | `UserController::ensureRoleOrStatusChangeIsAllowed()` rule 3 |

Users are **never deleted** (`Route::resource('users', ...)->except(['destroy'])`), because tickets,
comments and activities reference them with foreign keys.

**1. Business requirement.** Admins can switch accounts off without losing history. Switched-off accounts
must lose access immediately. The system must never end up with no admin, and no ticket may be left with an
agent who can no longer work on it.

**2. Realistic scenario.** Agent David leaves the company. The admin clicks **Deactivate** on the Users list.
David still has 2 In Progress tickets and 1 Resolved ticket, so the system refuses until the admin reassigns
the 2 active tickets and the customer (or admin) closes the Resolved one.

**3. Input data.**
- Toggle button: `PATCH /users/{user}/toggle-active` (no body; the new value is `! $user->is_active`).
- Edit form: `PUT /users/{user}` with `name`, `email`, `password` (optional), `password_confirmation`,
  `role`, `is_active` (checkbox, value `1`).

**4. Expected result.** If allowed: `users.is_active` flips and a success flash appears
("User David Chen was deactivated."). If not allowed: nothing changes and an error is shown.

**5. Actual implementation.**
- `app/Http/Controllers/UserController.php` → `toggleActive()`, `update()`, private `ensureRoleOrStatusChangeIsAllowed()`
- `app/Http/Controllers/AuthController.php` → `login()`
- `app/Http/Middleware/ActiveUserMiddleware.php` → `handle()` (alias `active` in `bootstrap/app.php`)

**6. Code explanation.**

```php
private function ensureRoleOrStatusChangeIsAllowed(User $user, string $newRole, bool $newIsActive, User $currentAdmin): void
{
    $isDeactivating = $user->is_active && ! $newIsActive;
    $isChangingRole = $user->role !== $newRole;

    if (! $isDeactivating && ! $isChangingRole) {
        return;
    }
```

- Only **deactivation** and **role change** are risky. Activation, or editing name/email only, returns early.

```php
    // Rule 1: an administrator cannot deactivate or demote themselves.
    if ($user->id === $currentAdmin->id) { throw ValidationException::withMessages([...]); }
```

- Stops an admin locking themselves out by accident. The Blade views also hide the toggle for your own row
  (`@if ($user->id !== auth()->id())` in `users/index.blade.php` and `users/show.blade.php`), but the backend
  check is the real protection (the edit form still exists for yourself).

```php
    // Rule 2: always keep at least one active administrator.
    if ($user->isAdmin() && $user->is_active) {
        $otherActiveAdmins = User::where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->count();
        if ($otherActiveAdmins === 0) { throw ...; }
    }
```

- Counts **other** active admins. If zero, the target is the last one.
- Honest note: through the HTTP routes this rule is a **defensive safety net**. The acting user must be an
  active admin (middleware `active` + `role:admin`), and rule 1 already blocks acting on yourself, so the
  actor is always "another active admin". The rule protects against future code paths (for example an
  Artisan command or seeder that calls the same logic).

```php
    // Rule 3: an agent responsible for unfinished or reopenable tickets must hand them over first.
    if ($user->isAgent()) {
        $responsibleTickets = Ticket::where('assigned_to', $user->id)
            ->whereIn('status', [Ticket::STATUS_ASSIGNED, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_RESOLVED])
            ->count();
        if ($responsibleTickets > 0) { throw ...; }
    }
}
```

- `Resolved` is included because a Resolved ticket can be **reopened**, and reopening requires an active
  assigned agent (see [§11](#11-reopening)). Resolved tickets **cannot be reassigned** (only
  Open/Assigned/In Progress can — `TicketPolicy::assign()`), so the only way to free the agent is to **close**
  their Resolved tickets first. This is a documented limitation.

Login and session checks:

```php
// AuthController::login() — correct password but inactive account
if (! Auth::user()->is_active) {
    Auth::logout();
    throw ValidationException::withMessages([
        'email' => 'Your account has been deactivated. Please contact an administrator.',
    ]);
}
```

```php
// ActiveUserMiddleware::handle() — runs on every route in the ['auth', 'active'] group
if ($user !== null && ! $user->is_active) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    ...
    if ($request->expectsJson()) {
        return response()->json(['message' => $message], 401);   // notification polling gets JSON
    }
    return redirect()->route('login')->with('error', $message);
}
```

- `$request->user()` is loaded from the database on each request, so `is_active` is always fresh.
- If removed: a deactivated user with an open browser tab could keep working until their session expires.

**7. DB state before** (deactivating agent id 3):

| users.id | name | role | is_active |
|---|---|---|---|
| 3 | David Chen | agent | 1 |

| tickets.id | assigned_to | status |
|---|---|---|
| 10 | 3 | in_progress |
| 11 | 3 | resolved |

**8. DB state after.** Blocked → nothing changes. After ticket 10 is reassigned and ticket 11 is closed, a
second click gives:

| users.id | name | role | is_active |
|---|---|---|---|
| 3 | David Chen | agent | **0** |

**9. Equivalent SQL.**

```sql
-- Rule 3 check
SELECT COUNT(*) FROM tickets
WHERE assigned_to = 3 AND status IN ('assigned', 'in_progress', 'resolved');

-- Rule 2 check
SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?;

-- The toggle itself
UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = 3;
```

**10. Edge cases.**
- Changing a customer to agent, or activating any user → always allowed (no risk).
- Changing an agent's role to customer with tickets → blocked by rule 3 (same as deactivation).
- Editing an agent's name/email only → allowed (no role/status change).
- Deactivated user with an open tab: next page load → redirected to login; next notification poll → 401
  JSON, `notifications.js` stops polling and shows "Your session has expired. Sign in again".
- Rule 3 also counts `Assigned` tickets that were never started.

**11. Error handling** (all on the `is_active` error key → red alert on the form/list):
- `You cannot deactivate your own account or change your own role.`
- `The last active administrator cannot be deactivated or demoted.`
- `This agent is still responsible for {N} unresolved or reopenable ticket(s). Reassign their Assigned/In Progress tickets and close their Resolved tickets first.`
- Login: `Your account has been deactivated. Please contact an administrator.` (error key `email`)

**12. Interview questions.**
- Why deactivate instead of delete? (Foreign keys use `restrictOnDelete`; history must stay readable.)
- How do you log out a user who is already logged in? (`ActiveUserMiddleware` on every request.)
- Why does rule 3 include Resolved tickets?
- Is the "last admin" rule reachable? (Defensive; explain why.)
- Test: `tests/Feature/UserAndCategoryManagementTest.php::test_agent_with_unfinished_tickets_cannot_be_deactivated_or_demoted`.

**13. How to modify.**
- Allow deactivation while agent has Resolved tickets: remove `Ticket::STATUS_RESOLVED` from the `whereIn`
  in rule 3. Then also decide what `TicketService::reopen()` should do (today it rejects inactive agents).
- Auto-unassign tickets on deactivation: in `toggleActive()`, wrap the update in `DB::transaction()` and set
  `assigned_to = null`, `status = 'open'` for the agent's Assigned tickets (and record activities). This is a
  bigger rule change — discuss it first.

---

## 2. Category management

**1. Business requirement.** Admins manage the list of ticket categories. Names are unique. Only **active**
categories can be chosen for new tickets. Categories used by tickets cannot be deleted (deactivate instead).

**2. Realistic scenario.** The company stops supporting its desktop app. The admin deactivates
"Legacy Desktop App" (this inactive category exists in the seeder). Old tickets still show it; new tickets
cannot choose it.

**3. Input data.** `POST /categories` or `PUT /categories/{category}` with `name`, `description`,
`is_active` (checkbox). `DELETE /categories/{category}` for delete.

**4. Expected result.** Category saved, both caches cleared, redirect to the list with "Category created." /
"Category updated." / "Category deleted.".

**5. Actual implementation.** `app/Http/Controllers/CategoryController.php` → `store()`, `update()`,
`destroy()`, private `clearCaches()`. `app/Models/Category.php` → `activeForDropdown()`, `clearActiveCache()`.

**6. Code explanation.**

```php
'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category->id)],
```

- `unique` with `ignore()` lets you save a category without renaming it (it ignores its own row).
- `$validated['is_active'] = $request->boolean('is_active');` — an unchecked checkbox sends nothing, so
  `boolean()` turns "missing" into `false`.

```php
public function destroy(Category $category)
{
    if ($category->tickets()->exists()) {
        return back()->with('error', 'This category is used by existing tickets and cannot be deleted. Deactivate it instead.');
    }
    $category->delete();
    $this->clearCaches();
    ...
}
```

- `exists()` runs a cheap `SELECT EXISTS(...)` instead of loading tickets.
- The database also protects this: `tickets.category_id` uses `restrictOnDelete()`. Without the PHP check,
  the user would see a database error page instead of a friendly message.

```php
private function clearCaches(): void
{
    Category::clearActiveCache();                         // ticket:active-categories
    Cache::forget(TicketService::DASHBOARD_CACHE_KEY);    // dashboard:admin:stats (category chart)
}
```

The "only active" rule for new tickets is enforced in `TicketController::store()` against the **database**,
not the cache:

```php
'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
```

**7. DB state before.**

| categories.id | name | is_active |
|---|---|---|
| 7 | Legacy Desktop App | 1 |

**8. DB state after** (edit, untick Active):

| categories.id | name | is_active |
|---|---|---|
| 7 | Legacy Desktop App | **0** |

Cache table: key `ticket:active-categories` and `dashboard:admin:stats` removed (the `cache` table stores
keys with the app's cache prefix).

**9. Equivalent SQL.**

```sql
UPDATE categories SET is_active = 0, updated_at = NOW() WHERE id = 7;
SELECT EXISTS(SELECT * FROM tickets WHERE tickets.category_id = 7) AS `exists`;   -- before delete
DELETE FROM categories WHERE id = 7;                                              -- only if unused
```

**10. Edge cases.**
- Editing a ticket whose category is now inactive: `TicketController::edit()` adds the current category to
  the dropdown (`$categories->concat([$ticket->category])`), and `update()` only requires an active
  category when the category **changes**.
- The ticket list filter shows **all** categories (`Category::orderBy('name')->get(...)`), so old tickets
  can still be filtered.
- The Delete button is only rendered when `tickets_count === 0` (`categories/index.blade.php`), but the
  controller re-checks.

**11. Error handling.**
- `This category is used by existing tickets and cannot be deleted. Deactivate it instead.` (flash `error`)
- Ticket form with inactive category: `Please choose an active category.` (key `category_id`)
- Duplicate name: Laravel's standard `The name has already been taken.`

**12. Interview questions.** Why soft-disable instead of delete? Why validate against the DB and not the
cached list? (Test: `tests/Feature/CacheTest.php::test_validation_never_trusts_a_stale_category_cache`.)

**13. How to modify.** Add a field (e.g. `color`): migration + `$fillable` in `Category` + rule in
`store()`/`update()` + input in `resources/views/categories/_form.blade.php`. If the dropdown needs it, add
the column to `->get(['id', 'name'])->toArray()` in `Category::activeForDropdown()` (keep `toArray()` +
`Category::hydrate($rows)` — the cache must hold a plain array, see [Caching guide §5.1](15-CACHING-GUIDE.md)).

---

## 3. Ticket creation

**1. Business requirement.** Any active user (admin, agent, customer) can create a ticket. It starts Open,
unassigned, with an SLA deadline and an activity record. The creator is always the logged-in user.
Every active administrator is told about the new ticket (in-app notification + queued email), so new work
is not missed. This admin alert was added at the project owner's request, beyond the original MASTER_PROMPT
§9.2 matrix.

**2. Realistic scenario.** Customer John cannot log in to the billing portal. He creates a ticket: subject
"Cannot access billing portal", category "Billing", priority High.

**3. Input data.** `POST /tickets`:

| Field | Rule (`TicketController::store()`) |
|---|---|
| `subject` | `required`, `string`, `max:255` |
| `description` | `required`, `string`, `max:5000` |
| `category_id` | `required`, `integer`, exists in `categories` with `is_active = true` |
| `priority` | `required`, `Rule::in(array_keys(Ticket::PRIORITIES))` → low/medium/high/critical |

**4. Expected result.** Redirect to `tickets.show` with "Ticket TK-000033 was created successfully.";
status Open; `due_at` = created_at + 8 hours; one `created` activity; one "New ticket created"
notification + one queued email per active admin; dashboard cache cleared.

**5. Actual implementation.** `TicketController::store()` → `TicketService::createTicket()`.
Authorization: `Gate::authorize('create', Ticket::class)` → `TicketPolicy::create()` returns `true` for all
(active users only reach it thanks to the `active` middleware).

**6. Code explanation.**

```php
public function createTicket(array $data, User $creator): Ticket
{
    $ticket = DB::transaction(function () use ($data, $creator) {
        $createdAt = now();

        $ticket = new Ticket();
        $ticket->fill($data);                     // only $fillable: subject, description, category_id, priority
        $ticket->created_by = $creator->id;       // From the logged-in user, never from the form.
        $ticket->assigned_to = null;
        $ticket->status = Ticket::STATUS_OPEN;
        $ticket->created_at = $createdAt;
        $ticket->due_at = Ticket::calculateDueAt($createdAt, $ticket->priority);
        $ticket->save();                          // INSERT -> the database generates the id.

        $ticket->ticket_number = Ticket::makeTicketNumber($ticket->id);
        $ticket->save();                          // UPDATE

        $this->recordActivity($ticket, $creator, 'created', null, 'Open');

        // Tell every active administrator that a new ticket is waiting (in-app + email).
        // notifyUser() skips an admin who created the ticket themselves.
        $admins = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->get();
        foreach ($admins as $admin) {
            $this->notifyUser($admin, $ticket, $creator, 'created',
                'New ticket created',
                "{$creator->name} created ticket {$ticket->ticket_number} \"{$ticket->subject}\".",
                true);
        }

        return $ticket;
    });

    $this->clearDashboardCache();
    return $ticket;
}
```

- `fill($data)` + `$fillable` = **mass-assignment protection**: even if a hacker posts `status=closed` or
  `created_by=1`, those keys are ignored.
- `created_at` is set explicitly so that `due_at` is calculated from **exactly** the same time.
- Everything is in one transaction: if the activity insert fails, the ticket insert is rolled back too.
- The admin loop: `notifyUser(..., true)` inserts the in-app notification inside the transaction and queues
  the email with `DB::afterCommit()`, so no email is sent for a ticket that was rolled back. Inactive admins
  and an admin who created the ticket themselves are skipped by `notifyUser()`. Agents get nothing yet.
- Cache is cleared **after** the transaction (only when the commit succeeded).

**7. DB state before.** `tickets` has 32 rows (seeded); last id 32.

**8. DB state after.**

| tickets.id | ticket_number | created_by | assigned_to | status | priority | created_at | due_at |
|---|---|---|---|---|---|---|---|
| 33 | TK-000033 | 6 (John) | NULL | open | high | 2026-11-25 10:00:00 | 2026-11-25 18:00:00 |

| ticket_activities.ticket_id | user_id | action | old_value | new_value |
|---|---|---|---|---|
| 33 | 6 | created | NULL | Open |

| notifications.notifiable_id | data.event_type | data.title |
|---|---|---|
| 1 (Nadia, admin) | created | New ticket created |

`jobs`: one `SendTicketEmail` row for Nadia (pushed after COMMIT).

**9. Equivalent SQL.**

```sql
START TRANSACTION;
SELECT COUNT(*) AS aggregate FROM categories WHERE id = 4 AND is_active = 1;        -- validation
INSERT INTO tickets (subject, description, category_id, priority, created_by, assigned_to, status,
                     created_at, due_at, updated_at)
VALUES ('Cannot access billing portal', '...', 4, 'high', 6, NULL, 'open',
        '2026-11-25 10:00:00', '2026-11-25 18:00:00', '2026-11-25 10:00:00');
UPDATE tickets SET ticket_number = 'TK-000033', updated_at = ... WHERE id = 33;
INSERT INTO ticket_activities (ticket_id, user_id, action, old_value, new_value, created_at, updated_at)
VALUES (33, 6, 'created', NULL, 'Open', ..., ...);
SELECT * FROM users WHERE role = 'admin' AND is_active = 1;
INSERT INTO notifications (...) VALUES (..., 1, '{"event_type":"created","title":"New ticket created",...}', ...);
COMMIT;
INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', '{...}', 0, ...);  -- afterCommit
DELETE FROM cache WHERE `key` = '<prefix>dashboard:admin:stats';
```

**10. Edge cases.** Inactive category chosen from a stale page → rejected. Agents and admins may create
tickets too; an agent who created a ticket can later act as its **creator** (close/reopen). On creation
only active admins are notified (in-app + email); an admin who creates a ticket is not notified about it,
and no agent is notified (nobody is assigned yet).

**11. Error handling.** Standard validation messages (e.g. `The subject field is required.`) plus the custom
`Please choose an active category.` A failed insert throws, the transaction rolls back, and Laravel shows a
500 error page in production.

**12. Interview questions.** Why not trust `created_by` from the form? Why a transaction for 2–3 inserts?
Why is the cache cleared outside the transaction closure?
(Tests: `TicketManagementTest::test_customer_can_create_a_ticket`, `test_inactive_category_is_rejected_for_new_tickets`,
`QueueAndEmailTest::test_new_ticket_notifies_and_emails_every_active_admin`, `test_admin_creating_a_ticket_is_not_notified_about_it`.)

**13. How to modify.** New required field: migration → `$fillable` → validation in `store()`/`update()` →
`resources/views/tickets/_form.blade.php`. Admin alert in-app only: change the last `notifyUser()` argument
in the `createTicket()` loop from `true` to `false`.

---

## 4. Ticket number generation

**1. Business requirement.** Every ticket gets a unique, human-friendly number like `TK-000001`.
`MAX(id) + 1` must not be used.

**2. Realistic scenario.** Two customers submit tickets in the same second. Both must get different numbers.

**3. Input data.** None from the user. The source is the auto-increment `tickets.id`.

**4. Expected result.** id 7 → `TK-000007`; id 1234567 → `TK-1234567` (padding only adds zeros up to 6 digits).

**5. Actual implementation.** `Ticket::makeTicketNumber(int $id)` called from `TicketService::createTicket()`.

**6. Code explanation.**

```php
public static function makeTicketNumber(int $id): string
{
    return 'TK-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}
```

- `str_pad(..., 6, '0', STR_PAD_LEFT)` adds zeros on the left until the string is 6 characters long.
- The id comes from the database **after** the INSERT. Auto-increment ids are never handed out twice, so the
  number is collision-safe.
- Why not `MAX(id) + 1`? Two requests could read the same MAX at the same time and both compute the same
  number. Auto-increment is allocated by the database engine atomically.
- Schema support: `ticket_number` is `string(20)->nullable()->unique()`. It is NULL for a tiny moment
  between the INSERT and the UPDATE (both inside the same transaction, so no other request sees the NULL).
  The `unique` index is a final safety net.

**7–8. DB state.**

| Step | tickets.id | ticket_number |
|---|---|---|
| After INSERT (inside transaction) | 33 | NULL |
| After UPDATE / COMMIT | 33 | TK-000033 |

**9. Equivalent SQL.** `INSERT ...;` then `UPDATE tickets SET ticket_number = 'TK-000033' WHERE id = 33;`

**10. Edge cases.** Gaps in numbers are normal (a rolled-back insert still consumes an auto-increment id).
Numbers above 999999 simply become longer.

**11. Error handling.** A duplicate would violate the unique index → `QueryException` → rollback. In
practice it cannot happen.

**12. Interview questions.** Why is MAX+1 unsafe? Why is the column nullable? What causes gaps?
(Test: `TicketManagementTest::test_ticket_numbers_are_unique`.)

**13. How to modify.** Change prefix or padding in `makeTicketNumber()` (e.g. `'RD-'`, 8 digits). Existing
rows keep their old numbers unless you write a data migration.

> **বাংলায়:** `MAX(id) + 1` ব্যবহার করলে একই সময়ে দুইটা request একই MAX পড়ে একই নম্বর বানিয়ে ফেলতে পারে
> (race condition)। Database-এর auto-increment id প্রতিবার আলাদা হয় — engine নিজেই এটা নিশ্চিত করে। তাই আগে
> INSERT করে id নিই, তারপর সেই id থেকে `TK-000033` বানাই। দুইটা কাজই একই transaction-এ, তাই মাঝের NULL অবস্থা
> অন্য কেউ দেখতে পায় না।

---

## 5. Manual assignment

**1. Business requirement.** An admin chooses an **active support agent** for a ticket. Open → Assigned.
The agent gets an in-app notification (no page refresh needed) and a queued email.

**2. Realistic scenario.** Admin opens TK-000033 (Open), selects "Sarah Ahmed (1 active)" and clicks
**Assign**.

**3. Input data.** `POST /tickets/{ticket}/assign` with `agent_id` (rule: `required`, `integer`,
`exists:users,id`).

**4. Expected result.** `assigned_to` = Sarah, status `assigned`, two activities (`assigned`,
`status_changed`), one `notifications` row for Sarah, one `SendTicketEmail` job after commit, dashboard
cache cleared, flash "Ticket assigned to Sarah Ahmed.".

**5. Actual implementation.**
- Route middleware: `auth`, `active`, `role:admin`.
- `TicketController::assign()` → `Gate::authorize('assign', $ticket)` (`TicketPolicy::assign()`: admin AND
  status in `ACTIVE_STATUSES`).
- `TicketService::assignAgent()` → `lockTicket()` → private `performAssignment()` → `recordActivity()`,
  `notifyUser()` → `queueEmail()`; then `clearDashboardCache()`.

**6. Code explanation.**

```php
public function assignAgent(Ticket $ticket, User $agent, User $admin): bool
{
    $wasChanged = DB::transaction(function () use ($ticket, $agent, $admin) {
        $lockedTicket = $this->lockTicket($ticket);          // SELECT ... FOR UPDATE
        return $this->performAssignment($lockedTicket, $agent, $admin);
    });

    if ($wasChanged) {
        $this->clearDashboardCache();
    }
    return $wasChanged;
}
```

Inside `performAssignment()`:

```php
if (! in_array($ticket->status, Ticket::ACTIVE_STATUSES, true)) { throw ... }   // status re-check on locked row

$agent = User::find($agent->id);                                                 // fresh from DB
if ($agent === null || ! $agent->isAgent() || ! $agent->is_active) { throw ... }

if ($ticket->assigned_to === $agent->id) {
    return false;                                                                // duplicate prevention
}

$previousAgent = $ticket->assignedAgent;           // null if the ticket was unassigned
$previousStatusLabel = $ticket->statusLabel();

$ticket->assigned_to = $agent->id;
if ($ticket->status === Ticket::STATUS_OPEN) {
    $ticket->status = Ticket::STATUS_ASSIGNED;     // Open -> Assigned happens through assignment.
}
$ticket->save();

if ($previousAgent === null) {
    $this->recordActivity($ticket, $admin, 'assigned', null, $agent->name);
    $this->recordActivity($ticket, $admin, 'status_changed', $previousStatusLabel, $ticket->statusLabel());
    $this->notifyUser($agent, $ticket, $admin, 'assigned', 'New ticket assigned', "...", true);
} else {
    // reassignment, see section 7
}
return true;
```

- `exists:users,id` only proves the user exists. The service re-reads the user and checks **role** and
  **is_active** — so a customer id or inactive agent id is rejected even if someone edits the HTML `<select>`.
- The last argument `true` of `notifyUser()` means "also queue an email".

**7. DB state before.**

| tickets.id | assigned_to | status |
|---|---|---|
| 33 | NULL | open |

**8. DB state after.**

| tickets.id | assigned_to | status |
|---|---|---|
| 33 | 2 (Sarah) | assigned |

| ticket_activities.action | old_value | new_value |
|---|---|---|
| assigned | NULL | Sarah Ahmed |
| status_changed | Open | Assigned |

| notifications.notifiable_id | type | data (JSON, shortened) | read_at |
|---|---|---|---|
| 2 | App\Notifications\TicketNotification | `{"ticket_id":33,"ticket_number":"TK-000033","title":"New ticket assigned",...,"event_type":"assigned"}` | NULL |

| jobs.queue | payload contains |
|---|---|
| default | `App\Jobs\SendTicketEmail` (recipient Sarah's email) |

**9. Equivalent SQL.**

```sql
START TRANSACTION;
SELECT * FROM tickets WHERE id = 33 LIMIT 1 FOR UPDATE;
SELECT * FROM users WHERE id = 2 LIMIT 1;
UPDATE tickets SET assigned_to = 2, status = 'assigned', updated_at = NOW() WHERE id = 33;
INSERT INTO ticket_activities (...) VALUES (33, 1, 'assigned', NULL, 'Sarah Ahmed', ...);
INSERT INTO ticket_activities (...) VALUES (33, 1, 'status_changed', 'Open', 'Assigned', ...);
INSERT INTO notifications (id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at)
VALUES ('<uuid>', 'App\\Notifications\\TicketNotification', 'App\\Models\\User', 2, '{...}', NULL, ...);
COMMIT;
INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', '{...}', 0, ...);  -- afterCommit
DELETE FROM cache WHERE `key` = '<prefix>dashboard:admin:stats';
```

**10. Edge cases.**
- Same agent selected again → `return false` → flash `info`: "The ticket is already assigned to Sarah Ahmed.
  Nothing was changed." No activity, no notification, no email, no cache clear.
- Resolved/Closed ticket → `TicketPolicy::assign()` false → 403.
- Non-admin posting directly → `role:admin` middleware → 403
  (`AuthorizationTest::test_non_admin_cannot_assign_by_posting_directly`).

**11. Error handling.**
- `Please select an active support agent.` (key `agent_id`)
- `Only Open, Assigned or In Progress tickets can be assigned.` (key `ticket`; only reachable if the status
  changed between the policy check and the lock — a race)
- Role middleware 403: `You do not have permission to access this page.`

**12. Interview questions.** Why re-read the agent inside the transaction? Why is the email queued after
commit? How do you avoid duplicate notifications? Walk me through the full chain (see
[07 — Assign Agent chain](07-UI-TO-BACKEND-MAPPING.md#chain-1-assign-agent)).

**13. How to modify.** Allow assigning to admins too: change the check in `performAssignment()` to
`! ($agent->isAgent() || $agent->isAdmin())` and include admins in `TicketController::agentList()`.
Disable assignment email: change the last `true` argument to `false`.

---

## 6. Automatic assignment

**1. Business requirement.** An **Auto Assign** button picks the active agent with the lowest workload
(Assigned + In Progress tickets). Ties are broken by the lowest user id. If no active agent exists, the
ticket stays Open with a clear message and nothing else happens.

**2. Realistic scenario.**

| Agent | id | Active tickets |
|---|---:|---:|
| Sarah | 2 | 5 |
| David | 3 | 2 |
| Fatima | 4 | 4 |

Admin clicks **Auto Assign** on TK-000033 → David is chosen.

**3. Input data.** `POST /tickets/{ticket}/auto-assign` — no body.

**4. Expected result.** Same database effects as manual assignment (section 5) with David as the agent.
Flash: "Ticket automatically assigned to David Chen (lowest current workload)."

**5. Actual implementation.** `TicketController::autoAssign()` → `TicketService::autoAssign()` →
`findLeastBusyAgent()` → `performAssignment()` (shared with manual assignment, so all rules are reused).

**6. Code explanation.**

```php
public function autoAssign(Ticket $ticket, User $admin): ?User
{
    $chosenAgent = DB::transaction(function () use ($ticket, $admin) {
        $lockedTicket = $this->lockTicket($ticket);

        if ($lockedTicket->status !== Ticket::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'ticket' => 'Auto assign is only available for Open tickets. Use Reassign instead.',
            ]);
        }

        $agent = $this->findLeastBusyAgent();
        if ($agent === null) {
            return null; // Nothing changes: no activity, no notification, no email.
        }

        $this->performAssignment($lockedTicket, $agent, $admin);
        return $agent;
    });

    if ($chosenAgent !== null) {
        $this->clearDashboardCache();
    }
    return $chosenAgent;
}
```

```php
public function findLeastBusyAgent(): ?User
{
    return User::where('role', User::ROLE_AGENT)
        ->where('is_active', true)
        ->withCount(['assignedTickets as workload_count' => function ($query) {
            $query->whereIn('status', Ticket::WORKLOAD_STATUSES);
        }])
        ->orderBy('workload_count')
        ->orderBy('id')
        ->first();
}
```

- `withCount()` adds a sub-query column `workload_count` to each user row. `as workload_count` renames it.
- The closure filters the counted tickets to `WORKLOAD_STATUSES` (assigned, in_progress).
- `orderBy('workload_count')` = lowest first; `orderBy('id')` = deterministic tie-breaker.
- `first()` = `LIMIT 1`; returns `null` when there are no active agents.

**7. DB state before.** See scenario table; ticket 33 is `open`, `assigned_to` NULL.

**8. DB state after.** ticket 33: `assigned_to = 3`, `status = assigned`; activities `assigned` +
`status_changed`; one notification for David; one email job.

**9. Equivalent SQL.**

```sql
SELECT users.*,
       (SELECT COUNT(*) FROM tickets
        WHERE users.id = tickets.assigned_to
          AND status IN ('assigned', 'in_progress')) AS workload_count
FROM users
WHERE role = 'agent' AND is_active = 1
ORDER BY workload_count ASC, id ASC
LIMIT 1;
```

**10. Edge cases.**
- Tie (two agents with 2 tickets) → lower id wins (`TicketWorkflowTest::test_auto_assign_uses_lowest_user_id_as_tie_breaker`).
- No active agents → `null` → flash error, ticket stays Open, no activity/notification/email/cache clear
  (`test_auto_assign_without_active_agents_leaves_ticket_open`).
- Ticket already Assigned/In Progress → passes the policy (admin + active status) but the service throws
  the "only for Open tickets" error. The button is only rendered for Open tickets in `tickets/show.blade.php`.
- **Documented limitation:** two admins auto-assigning **two different tickets** at the same moment may both
  pick the same least-busy agent. The row lock protects only the **ticket** row, not the agent's workload.
  This is accepted; no distributed locking is used.
- **This is application-level workload distribution, not infrastructure load balancing.** It spreads
  *work among people*; it does not spread *HTTP traffic among servers* (that is what Nginx/HAProxy/AWS ELB do).

**11. Error handling.**
- `Auto assign is only available for Open tickets. Use Reassign instead.` (key `ticket`)
- No agent: flash `error` — `No active support agent is available. The ticket remains Open.`

**12. Interview questions.** Explain `withCount()`. Why a tie-breaker? What race condition remains, and how
could you fix it (e.g. lock the chosen agent row with `lockForUpdate()` while counting, or a cache lock)?
Difference between workload distribution and load balancing?

**13. How to modify.**
- Count only In Progress: change `Ticket::WORKLOAD_STATUSES` (this also changes the dropdown counts and
  the dashboard "active" column).
- Weight by priority: replace `withCount` with a `withSum`/raw sub-query — more complex; explain trade-off.
- Round-robin instead: store last assigned agent id and pick the next id.

> **বাংলায়:** Auto assign মানে "যার হাতে সবচেয়ে কম কাজ, তাকে দাও"। `withCount()` প্রত্যেক agent-এর জন্য একটা
> sub-query চালিয়ে তার Assigned/In Progress ticket গুনে `workload_count` বানায়, তারপর ছোট থেকে বড় সাজিয়ে প্রথমজনকে
> নেয়। সমান হলে ছোট id জেতে, যাতে ফলাফল সবসময় একই হয়। এটা মানুষের মধ্যে কাজ ভাগ করা — server-এর মধ্যে traffic
> ভাগ করা (load balancer) নয়।

---

## 7. Reassignment

**1. Business requirement.** Admins can move an **Assigned** or **In Progress** ticket to another active
agent. Status is kept. Previous and new agent are recorded. Only the new agent is notified (in-app + email).
No duplicates if the agent did not change.

**2. Realistic scenario.** Sarah is on leave. Admin reassigns TK-000020 (In Progress, Sarah) to Fatima.

**3. Input data.** Same form and route as manual assignment: `POST /tickets/{ticket}/assign`, `agent_id`.
The button label changes to **Reassign** when `$ticket->assigned_to` is set.

**4. Expected result.** `assigned_to` = Fatima, status stays `in_progress`, one `reassigned` activity
(old = Sarah, new = Fatima), one notification + email for Fatima, nothing for Sarah. The ticket disappears
from Sarah's list (her list is `visibleTo()` = created by her OR assigned to her).

**5. Actual implementation.** `TicketService::assignAgent()` → `performAssignment()` `else` branch.

**6. Code explanation.**

```php
} else {
    $this->recordActivity($ticket, $admin, 'reassigned', $previousAgent->name, $agent->name);

    $this->notifyUser($agent, $ticket, $admin, 'reassigned',
        'Ticket reassigned to you',
        "Ticket {$ticket->ticket_number} \"{$ticket->subject}\" has been reassigned to you.",
        true);
}
```

- Status is untouched because the `if ($ticket->status === Ticket::STATUS_OPEN)` block only runs for Open.
- No `status_changed` activity for reassignment (status did not change).

**7. DB state before.**

| tickets.id | assigned_to | status |
|---|---|---|
| 20 | 2 (Sarah) | in_progress |

**8. DB state after.**

| tickets.id | assigned_to | status |
|---|---|---|
| 20 | 4 (Fatima) | in_progress |

| ticket_activities.action | old_value | new_value |
|---|---|---|
| reassigned | Sarah Ahmed | Fatima Khan |

**9. Equivalent SQL.** `UPDATE tickets SET assigned_to = 4, updated_at = NOW() WHERE id = 20;` + activity
INSERT + notification INSERT (+ job INSERT after commit).

**10. Edge cases.** Same agent → no-op with `info` flash. Resolved/Closed → 403 (test
`test_resolved_or_closed_tickets_cannot_be_reassigned`). The previous agent gets no notification
(`test_reassignment_keeps_status_and_notifies_new_agent`).

**11. Error handling.** Same messages as section 5.

**12. Interview questions.** Why not notify the old agent? Why can't Resolved tickets be reassigned? (Spec
allows reassignment only for Assigned/In Progress; this creates the agent-deactivation limitation in
[§1](#1-user-activation-and-deactivation).)

**13. How to modify.** Notify the old agent: add `$this->notifyUser($previousAgent, $ticket, $admin,
'unassigned', ..., false);` in the `else` branch (a new event type — also add it to the docs/matrix).

---

## 8. Workload calculation

**1. Business requirement.** An agent's **workload** = number of tickets assigned to them with status
Assigned or In Progress. Used by auto-assign, the assign dropdown, and dashboards.

**2. Realistic scenario.** Admin opens the assign dropdown and sees "David Chen (2 active)".

**3. Input data.** None.

**4. Expected result.** Counts match the definition everywhere.

**5. Actual implementation.**
- Constant: `Ticket::WORKLOAD_STATUSES = [STATUS_ASSIGNED, STATUS_IN_PROGRESS]`.
- `TicketService::findLeastBusyAgent()` (auto-assign).
- `TicketController::agentList()` (dropdown + admin filter) — same `withCount` with `workload_count`.
- `DashboardController::adminDashboard()` → `active_count`, plus `overdue_count` and `resolved_count`
  (Resolved + Closed) per agent (all agents, including inactive).
- `DashboardController::agentDashboard()` → `pending` (assigned), `in_progress`, etc.
- `UserController::show()` → `$assignedActiveCount`.

**6. Code explanation.** One constant drives every count, so changing the definition is a one-line change.
Resolved tickets are **not** workload (the agent finished them), but they still block deactivation (§1).

**7–8. DB state.** Read-only; no changes.

**9. Equivalent SQL.**

```sql
SELECT COUNT(*) FROM tickets WHERE assigned_to = 3 AND status IN ('assigned', 'in_progress');
```

The composite index `tickets(assigned_to, status)` (tickets migration) makes this fast.

**10. Edge cases.** Overdue tickets count the same as non-overdue. Priority is ignored.

**11. Error handling.** None (read-only).

**12. Interview questions.** Why a composite index on `(assigned_to, status)`? Why use `withCount()` instead
of loading tickets and counting in PHP? (One query, no N+1.)

**13. How to modify.** Edit `Ticket::WORKLOAD_STATUSES`.

---

## 9. Status transitions

**1. Business requirement.** Only these transitions are valid:

| From | To | Action (button) | Who |
|---|---|---|---|
| Open | Assigned | Assign / Auto Assign | Admin |
| Assigned | In Progress | Start Working | Assigned agent or admin |
| In Progress | Resolved | Resolve | Assigned agent or admin |
| Resolved | Closed | Confirm & Close | Creator or admin |
| Resolved | In Progress | Reopen | Creator or admin |

Closed tickets are read-only. The edit form never changes status.

**2. Realistic scenario.** Agent Sarah opens an Assigned ticket and clicks **Start Working**.

**3. Input data.** `POST /tickets/{ticket}/start` (no body).

**4. Expected result.** Status `in_progress`, activity `status_changed` (Assigned → In Progress), dashboard
cache cleared, flash "Ticket is now In Progress.". **No notification** is sent for Start Working.

**5. Actual implementation.**
- Central table: `Ticket::TRANSITIONS` + `Ticket::canPerform()`.
- Policy: `TicketPolicy::start/resolve/close/reopen()` combine WHO + `canPerform()`.
- Service: `TicketService::startWork()`, `resolve()`, `close()`, `reopen()`, each calling
  `ensureActionAllowed()` on the locked row.

**6. Code explanation.**

```php
public const TRANSITIONS = [
    'start' => ['from' => self::STATUS_ASSIGNED, 'to' => self::STATUS_IN_PROGRESS],
    'resolve' => ['from' => self::STATUS_IN_PROGRESS, 'to' => self::STATUS_RESOLVED],
    'close' => ['from' => self::STATUS_RESOLVED, 'to' => self::STATUS_CLOSED],
    'reopen' => ['from' => self::STATUS_RESOLVED, 'to' => self::STATUS_IN_PROGRESS],
];

public function canPerform(string $action): bool
{
    return $this->status === self::TRANSITIONS[$action]['from'];
}
```

```php
// TicketPolicy
public function start(User $user, Ticket $ticket): bool
{
    return ($user->isAdmin() || $this->isAssignedAgent($user, $ticket))
        && $ticket->canPerform('start');
}
```

```php
// TicketService
public function startWork(Ticket $ticket, User $user): void
{
    DB::transaction(function () use ($ticket, $user) {
        $lockedTicket = $this->lockTicket($ticket);
        $this->ensureActionAllowed($lockedTicket, 'start');

        $lockedTicket->status = Ticket::STATUS_IN_PROGRESS;
        $lockedTicket->save();

        $this->recordActivity($lockedTicket, $user, 'status_changed', 'Assigned', 'In Progress');
    });

    $this->clearDashboardCache();
}
```

- The status is checked **twice**: in the policy (on the route-bound ticket; gives 403 and hides buttons
  via `@can`) and in `ensureActionAllowed()` (on the locked row; protects against a race where two people
  click at the same time).
- Open → Assigned is not in `TRANSITIONS`; it only happens inside `performAssignment()`.
- `TicketController::update()` validates only subject/description/category_id/priority, and `status` is not
  in `$fillable`, so the edit form cannot change status.

**7. DB state before.** ticket 33: `status = assigned`, `assigned_to = 2`.

**8. DB state after.** ticket 33: `status = in_progress`; activity `status_changed` Assigned → In Progress.

**9. Equivalent SQL.**

```sql
START TRANSACTION;
SELECT * FROM tickets WHERE id = 33 LIMIT 1 FOR UPDATE;
UPDATE tickets SET status = 'in_progress', updated_at = NOW() WHERE id = 33;
INSERT INTO ticket_activities (...) VALUES (33, 2, 'status_changed', 'Assigned', 'In Progress', ...);
COMMIT;
```

**10. Edge cases.** Open → Closed, Assigned → Resolved, Open → In Progress: all 403
(`TicketWorkflowTest::test_invalid_status_transitions_are_rejected`). Customer trying Resolve → 403. Agent
acting on another agent's ticket → 403.

**11. Error handling.**
- Policy failure → 403 "This action is unauthorized." page.
- Race on the locked row → `This action is not allowed while the ticket is {Status}.` (key `ticket`)

**12. Interview questions.** Why not a state-machine package? Why check status in both policy and service?
How do you add a new status?

**13. How to modify.** Add a status "On Hold": add `STATUS_ON_HOLD` + label in `STATUSES`, add transitions
(e.g. `'hold' => ['from' => in_progress, 'to' => on_hold]`), decide if it belongs in `ACTIVE_STATUSES` /
`WORKLOAD_STATUSES`, add a policy method, a service method, a route, a button in `tickets/show.blade.php`,
and a badge colour in `resources/views/partials/ticket-badges.blade.php`. Note `TRANSITIONS` allows only
one `from` status per action.

> **বাংলায়:** `TRANSITIONS` array হলো একটা ছোট "নিয়মের টেবিল" — কোন action কোন status থেকে কোন status-এ নিতে
> পারে। Policy এটা দেখে button দেখাবে কিনা ঠিক করে, আর Service lock করা row-তে আবার দেখে। দুইবার দেখার কারণ: দুইজন
> একসাথে click করলে প্রথমজন status বদলে দেয়, দ্বিতীয়জন lock পাওয়ার পর নতুন status দেখে আটকে যায়।

---

## 10. Resolution

**1. Business requirement.** Resolving requires a resolution note, sets `resolved_at`, notifies the creator
(in-app + email).

**2. Realistic scenario.** Sarah fixed John's billing login and writes "Reset your SSO session; please try
again." then clicks **Resolve Ticket**.

**3. Input data.** `POST /tickets/{ticket}/resolve`, `resolution_note` (`required`, `string`, `max:5000`).

**4. Expected result.** status `resolved`, `resolved_at` = now, note saved, activity `resolved`, John gets
notification + email, cache cleared, flash "Ticket marked as Resolved.".

**5. Actual implementation.** `TicketController::resolve()` → `TicketService::resolve()`.

**6. Code explanation.**

```php
public function resolve(Ticket $ticket, string $resolutionNote, User $user): void
{
    if (trim($resolutionNote) === '') {
        throw ValidationException::withMessages([
            'resolution_note' => 'A resolution note is required to resolve a ticket.',
        ]);
    }

    DB::transaction(function () use ($ticket, $resolutionNote, $user) {
        $lockedTicket = $this->lockTicket($ticket);
        $this->ensureActionAllowed($lockedTicket, 'resolve');

        $lockedTicket->status = Ticket::STATUS_RESOLVED;
        $lockedTicket->resolved_at = now();
        $lockedTicket->resolution_note = $resolutionNote;
        $lockedTicket->save();

        $this->recordActivity($lockedTicket, $user, 'resolved', 'In Progress', 'Resolved');

        $this->notifyUser($lockedTicket->creator, $lockedTicket, $user, 'resolved',
            'Ticket resolved',
            "Ticket {$lockedTicket->ticket_number} has been resolved. Please review the resolution note.",
            true);
    });

    $this->clearDashboardCache();
}
```

- The `trim()` check is a second line of defence: the controller's `required` rule already rejects empty
  input (Laravel's default middleware also trims strings and converts empty strings to null).

**7. DB state before.**

| id | status | resolved_at | resolution_note |
|---|---|---|---|
| 33 | in_progress | NULL | NULL |

**8. DB state after.**

| id | status | resolved_at | resolution_note |
|---|---|---|---|
| 33 | resolved | 2026-11-25 14:30:00 | Reset your SSO session; please try again. |

**9. Equivalent SQL.**

```sql
UPDATE tickets SET status = 'resolved', resolved_at = '2026-11-25 14:30:00',
       resolution_note = 'Reset your SSO session; please try again.', updated_at = NOW()
WHERE id = 33;
```

**10. Edge cases.** Creator is the resolver (e.g. an agent resolving their own ticket, or an admin who created
it) → `notifyUser()` skips self-notification. Inactive creator → skipped. `resolved_at` and `due_at` are
DATETIME columns on purpose (MariaDB would otherwise auto-update the first TIMESTAMP column on every UPDATE).

**11. Error handling.** `The resolution note field is required.` (controller validation) or
`A resolution note is required to resolve a ticket.` (service). Wrong status → 403 or
`This action is not allowed while the ticket is ....`

**12. Interview questions.** Why is `resolved_at` stored rather than calculated? (Used for average
resolution time.) Why DATETIME instead of TIMESTAMP?

**13. How to modify.** Minimum note length: add `'min:10'` to the rule in `TicketController::resolve()`.

---

## 11. Reopening

**1. Business requirement.** The creator (or admin) can reopen a Resolved ticket → In Progress, only if the
assigned agent is still active. `resolved_at` and the note are cleared; the old note is kept in the activity
history. The agent is notified (in-app + email). **The original SLA deadline is kept.**

**2. Realistic scenario.** John tries again and still cannot log in. He clicks **Reopen Ticket**. The
original deadline (18:00) has already passed (it is now 19:00), so the ticket is **immediately overdue**.

**3. Input data.** `POST /tickets/{ticket}/reopen` (no body).

**4. Expected result.** status `in_progress`, `resolved_at` NULL, `resolution_note` NULL, activity
`reopened` with old value "Previous resolution: …", notification + email to Sarah, flash
"Ticket reopened and moved back to In Progress.".

**5. Actual implementation.** `TicketController::reopen()` → `TicketService::reopen()`.

**6. Code explanation.**

```php
$agent = $lockedTicket->assignedAgent;
if ($agent === null || ! $agent->is_active) {
    throw ValidationException::withMessages([
        'ticket' => 'This ticket cannot be reopened because its assigned agent is not active. Please contact an administrator.',
    ]);
}

$previousNote = $lockedTicket->resolution_note;

$lockedTicket->status = Ticket::STATUS_IN_PROGRESS;
$lockedTicket->resolved_at = null;
$lockedTicket->resolution_note = null;
$lockedTicket->save();

$this->recordActivity($lockedTicket, $user, 'reopened', 'Previous resolution: ' . $previousNote, 'In Progress');
```

- `due_at` is **not** touched. **Deliberate simplified business rule:** the SLA clock is not reset on reopen,
  so a reopened ticket whose deadline has passed shows as overdue at once. This keeps the rule simple and
  honest ("the customer has been waiting since creation").

**7. DB state before** (now = 19:00):

| id | status | due_at | resolved_at | resolution_note |
|---|---|---|---|---|
| 33 | resolved | 2026-11-25 18:00 | 2026-11-25 14:30 | Reset your SSO session... |

**8. DB state after.**

| id | status | due_at | resolved_at | resolution_note |
|---|---|---|---|---|
| 33 | in_progress | 2026-11-25 18:00 (unchanged) | NULL | NULL |

`isOverdue()` → true (active status and now > due_at).

**9. Equivalent SQL.**

```sql
UPDATE tickets SET status = 'in_progress', resolved_at = NULL, resolution_note = NULL, updated_at = NOW()
WHERE id = 33;
INSERT INTO ticket_activities (..., action, old_value, new_value)
VALUES (..., 'reopened', 'Previous resolution: Reset your SSO session; please try again.', 'In Progress');
```

**10. Edge cases.** Inactive agent → rejected (normally prevented by §1 rule 3, but a defensive check exists;
`test_reopen_requires_an_active_assigned_agent`). Closed tickets cannot be reopened (403). Reopen by admin
who is also the agent → no self-notification.

**11. Error handling.** `This ticket cannot be reopened because its assigned agent is not active. Please contact an administrator.` (key `ticket`)

**12. Interview questions.** Why keep the original SLA? What would you change to reset it? Where is the old
resolution note kept?

**13. How to modify.** Reset the SLA on reopen: inside `reopen()`, add
`$lockedTicket->due_at = Ticket::calculateDueAt(now(), $lockedTicket->priority);` and record a
`due_date_changed` activity.

---

## 12. Closure

**1. Business requirement.** The creator (or admin) confirms a Resolved ticket → Closed. `resolved_at` is
preserved. The agent gets an **in-app only** notification. Closed tickets are read-only.

**2. Realistic scenario.** John confirms the fix and clicks **Confirm & Close Ticket**.

**3. Input data.** `POST /tickets/{ticket}/close`.

**4. Expected result.** status `closed`, activity `closed`, notification for Sarah, **no email**, flash
"Ticket closed. Thank you for confirming the resolution.".

**5. Actual implementation.** `TicketController::close()` → `TicketService::close()`.

**6. Code explanation.**

```php
$lockedTicket->status = Ticket::STATUS_CLOSED;
$lockedTicket->save();

$this->recordActivity($lockedTicket, $user, 'closed', 'Resolved', 'Closed');

// In-app only (no email) for closure.
$this->notifyUser($lockedTicket->assignedAgent, $lockedTicket, $user, 'closed',
    'Ticket closed', "Ticket {$lockedTicket->ticket_number} was confirmed and closed.", false);
```

Read-only is enforced by the policy: `update()` returns false when `isClosed()`, `comment()` requires
`! $ticket->isClosed()`, `assign()` requires an active status, and Closed has no entry as a `from` in
`TRANSITIONS`. `addComment()` also re-checks `isClosed()` on the locked row.

**7–8. DB state.** `status`: resolved → closed; `resolved_at` unchanged.

**9. Equivalent SQL.** `UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id = 33;`

**10. Edge cases.** Agent cannot close (not creator) unless they created the ticket. Closing an Open ticket →
403. Comment on a closed ticket → 403 (`test_closed_tickets_are_read_only`).

**11. Error handling.** Policy 403; race → `This action is not allowed while the ticket is Closed.`

**12. Interview questions.** Why no email on close? How is "read-only" enforced — UI or backend? (Both;
backend is the real one.)

**13. How to modify.** Auto-close after X days: see [§23 Scheduler](#23-known-limitations-and-future-improvements).
Add email on close: change the last argument to `true`.

---

## 13. SLA calculation (and priority change)

**1. Business requirement.** `due_at` = creation time + hours by priority (continuous calendar hours; no
business hours, weekends or holidays). If an admin changes priority, `due_at` is recalculated **from the
original creation time** and both changes are logged.

| Priority | Hours (`Ticket::SLA_HOURS`) |
|---|---:|
| low | 48 |
| medium | 24 |
| high | 8 |
| critical | 2 |

**2. Realistic scenario.** Ticket created 10:00 as Medium (due tomorrow 10:00). At 11:30 the admin changes it
to Critical → new due 12:00 today (10:00 + 2h), not 13:30.

**3. Input data.** Creation form `priority`, or edit form `PUT /tickets/{ticket}` with a new `priority`.

**4. Expected result.** `due_at` = 12:00; activities `priority_changed` (Medium → Critical) and
`due_date_changed` (old → new, formatted `d M Y, h:i A`).

**5. Actual implementation.** `Ticket::calculateDueAt()`; used by `TicketService::createTicket()` and
`TicketService::updateTicket()`.

**6. Code explanation.**

```php
public static function calculateDueAt(Carbon $createdAt, string $priority): Carbon
{
    $hours = self::SLA_HOURS[$priority];
    return $createdAt->copy()->addHours($hours);
}
```

- `copy()` matters: Carbon objects are **mutable**; without `copy()`, `addHours()` would also change the
  ticket's `created_at` object.

```php
// TicketService::updateTicket()
$ticket->fill($data);
if (! $ticket->isDirty()) {
    return false;                            // nothing changed -> no activity, "No changes were made."
}
$oldPriority = $ticket->getOriginal('priority');
$oldDueAt = $ticket->due_at;
$priorityChanged = $ticket->isDirty('priority');
...
DB::transaction(function () use (...) {
    if ($priorityChanged) {
        $ticket->due_at = Ticket::calculateDueAt($ticket->created_at, $ticket->priority);
    }
    $ticket->save();
    if (count($changedFields) > 0) {
        $this->recordActivity($ticket, $editor, 'updated', null, 'Changed: ' . implode(', ', $changedFields));
    }
    if ($priorityChanged) {
        $this->recordActivity($ticket, $editor, 'priority_changed', Ticket::PRIORITIES[$oldPriority], $ticket->priorityLabel());
        $this->recordActivity($ticket, $editor, 'due_date_changed', $this->formatDate($oldDueAt), $this->formatDate($ticket->due_at));
    }
});
$this->clearDashboardCache();
```

- `isDirty()` compares new values with the originals loaded from the DB.
- Who can edit: `TicketPolicy::update()` — admin on any non-closed ticket; creator only while Open and
  unassigned. So a customer can change priority **only before assignment**.

**7. DB state before.**

| id | priority | created_at | due_at |
|---|---|---|---|
| 40 | medium | 2026-11-25 10:00 | 2026-11-26 10:00 |

**8. DB state after.**

| id | priority | created_at | due_at |
|---|---|---|---|
| 40 | critical | 2026-11-25 10:00 | 2026-11-25 12:00 |

| action | old_value | new_value |
|---|---|---|
| priority_changed | Medium | Critical |
| due_date_changed | 26 Nov 2026, 10:00 AM | 25 Nov 2026, 12:00 PM |

**9. Equivalent SQL.**

```sql
UPDATE tickets SET priority = 'critical', due_at = DATE_ADD(created_at, INTERVAL 2 HOUR), updated_at = NOW()
WHERE id = 40;   -- PHP computes the value; this SQL shows the meaning
```

**10. Edge cases.** Lowering priority on an overdue ticket can make it not overdue. Raising priority can make
it overdue instantly. No changes → "No changes were made." and no activity
(`test_editing_without_changes_records_no_activity`). Reopen does **not** recalculate (see §11).

**11. Error handling.** Invalid priority → `The selected priority is invalid.` (standard `in` rule).

**12. Interview questions.** Why recalculate from `created_at` and not from now? Why `copy()`? Why DATETIME
for `due_at`? How would you support business hours? (Out of scope; would need a calendar.)

**13. How to modify.** Change hours in `Ticket::SLA_HOURS`. Existing tickets keep their stored `due_at`; a
data migration would be needed to update them. Note: `updateTicket()` does not lock the row (simple edit; see
§18 limitations).

---

## 14. Overdue calculation

**1. Business requirement.** A ticket is overdue when **now > due_at** AND status is Open, Assigned or In
Progress. Resolved/Closed are never overdue. It is calculated live, never stored.

**2. Realistic scenario.** A High ticket created 09:00 (due 17:00) still In Progress at 17:05 → red "Overdue"
badge in lists and an "Overdue by …" badge on the details page.

**3. Input data.** None (time-based). Filter: `?overdue=1` on the ticket list.

**4. Expected result.** Badge + red row in list; counted in dashboard "Overdue" cards.

**5. Actual implementation.** `Ticket::isOverdue()` (one model, for display) and `Ticket::scopeOverdue()`
(SQL, for counts/filters).

**6. Code explanation.**

```php
public function isOverdue(): bool
{
    if (! in_array($this->status, self::ACTIVE_STATUSES, true)) {
        return false;
    }
    return now()->greaterThan($this->due_at);
}

public function scopeOverdue(Builder $query): Builder
{
    return $query->whereIn('status', self::ACTIVE_STATUSES)->where('due_at', '<', now());
}
```

- Usage: `Ticket::overdue()->count()` — Laravel turns `scopeOverdue` into `overdue()`.
- The admin "Overdue" card is computed **outside** the 60-second cache on purpose (`$overdueCount` in
  `DashboardController::adminDashboard()`), because time passing changes it without any database write.

**7–8. DB state.** No writes.

**9. Equivalent SQL.**

```sql
SELECT COUNT(*) FROM tickets WHERE status IN ('open','assigned','in_progress') AND due_at < NOW();
```

(`now()` is passed from PHP as a bound value, in the app timezone.)

**10. Edge cases.** Exactly equal to `due_at` → not overdue (`greaterThan` / `<`). Reopened ticket with past
deadline → overdue immediately (§11).

**11. Error handling.** None.

**12. Interview questions.** Why not store an `is_overdue` column? (It would go stale every minute without a
scheduler.) Why exclude it from the cache?

**13. How to modify.** Add a grace period: `now()->greaterThan($this->due_at->copy()->addMinutes(15))` and the
same in the scope (`now()->subMinutes(15)`). Keep both in sync.

> **বাংলায়:** Overdue কোনো column-এ save করা হয় না, কারণ সময় নিজে থেকেই চলে — কোনো database update ছাড়াই একটা
> ticket ৫টায় ঠিক আছে, ৫:০১-এ overdue। তাই প্রতিবার দেখানোর সময় `now()` দিয়ে হিসাব করা হয়। একই কারণে dashboard
> cache-এ overdue সংখ্যা রাখা হয় না।

---

## 15. Comments and the recipient rule

**1. Business requirement.** Anyone who can view a non-closed ticket can comment. Participants other than the
author get an **in-app only** notification. No duplicates, no self-notification, inactive users skipped.

**2. Realistic scenario.** John comments "Still failing on Chrome" → Sarah (assigned agent) is notified.
Sarah replies → John is notified. Admin comments → both are notified.

**3. Input data.** `POST /tickets/{ticket}/comments`, `message` (`required`, `string`, `max:5000`).

**4. Expected result.** Row in `ticket_comments`, notifications for the other participants, redirect to
`tickets.show` + `#conversation`, flash "Comment added.".

**5. Actual implementation.** `TicketCommentController::store()` → `Gate::authorize('comment', $ticket)` →
`TicketService::addComment()`.

**6. Code explanation.**

```php
$recipients = [$lockedTicket->creator, $lockedTicket->assignedAgent];
$alreadyNotifiedIds = [];

foreach ($recipients as $recipient) {
    if ($recipient === null || in_array($recipient->id, $alreadyNotifiedIds, true)) {
        continue; // No agent yet, or creator and agent are the same person.
    }
    $alreadyNotifiedIds[] = $recipient->id;

    $this->notifyUser($recipient, $lockedTicket, $author, 'comment',
        'New comment from ' . $author->name,
        "New comment on ticket {$lockedTicket->ticket_number}: " . mb_strimwidth($message, 0, 80, '...'),
        false);
}
```

**The recipient rule:** recipients = {creator, assigned agent} − {author} − {inactive} − {duplicates}.
This single rule covers all cases:

| Author | Creator gets | Agent gets |
|---|---|---|
| Creator (customer) | — (self) | Yes |
| Assigned agent | Yes | — (self) |
| Admin (not creator/agent) | Yes | Yes |
| Creator who is also the agent | — | — |
| Comment on unassigned ticket by creator | — | (no agent) → nobody |

- `mb_strimwidth($message, 0, 80, '...')` shortens the preview safely for multi-byte text (e.g. Bangla).
- Comments do **not** create a `ticket_activities` row and do **not** clear the dashboard cache.
- `message` is set explicitly; `ticket_id` and `user_id` come from the route and the logged-in user
  (`TicketComment::$fillable` only contains `message`).

**7. DB state before.** `ticket_comments` has no rows for ticket 33.

**8. DB state after.**

| ticket_comments.ticket_id | user_id | message |
|---|---|---|
| 33 | 6 (John) | Still failing on Chrome |

| notifications.notifiable_id | data.event_type | data.title |
|---|---|---|
| 2 (Sarah) | comment | New comment from John Carter |

**9. Equivalent SQL.**

```sql
START TRANSACTION;
SELECT * FROM tickets WHERE id = 33 LIMIT 1 FOR UPDATE;
INSERT INTO ticket_comments (ticket_id, user_id, message, created_at, updated_at) VALUES (33, 6, '...', ...);
SELECT * FROM users WHERE id = 6;  SELECT * FROM users WHERE id = 2;      -- creator, assignedAgent
INSERT INTO notifications (...) VALUES (..., 2, '{"event_type":"comment",...}', ...);
COMMIT;
```

**10. Edge cases.** Closed ticket → 403 (policy) or `Closed tickets are read-only. Comments are not allowed.`
(race). Admin is the creator → only the agent is notified. Tests:
`NotificationTest::test_creator_comment_notifies_only_the_assigned_agent`, `test_agent_reply_notifies_the_creator`,
`test_admin_comment_notifies_creator_and_agent`, `test_no_duplicate_when_creator_is_also_the_assigned_agent`,
`test_inactive_recipient_is_skipped`.

**11. Error handling.** `The message field is required.`; `Closed tickets are read-only. Comments are not allowed.` (key `message`).

**12. Interview questions.** How do you avoid duplicate notifications? Why are comment emails not sent?
(Spec matrix: comments are in-app only.)

**13. How to modify.** Email for comments: change `false` → `true` in `addComment()`. Notify all admins too:
append `User::where('role', 'admin')->where('is_active', true)->get()` to `$recipients`.

---

## 16. Activity logging

**1. Business requirement.** Every important ticket change is recorded (who, what, old value, new value,
when) and shown as a timeline on the ticket page.

**2. Realistic scenario.** An auditor asks "who changed the priority of TK-000040 and when?" → the timeline
shows "Nadia Rahman (admin) changed the priority Medium → Critical".

**3. Input data.** Written by the service, never by the user.

**4. Expected result.** One row per change; never for no-op operations.

**5. Actual implementation.** `TicketService::recordActivity()`; labels in `TicketActivity::ACTION_LABELS`;
displayed in `resources/views/tickets/show.blade.php` (Activity History card).

**6. Code explanation.**

```php
private function recordActivity(Ticket $ticket, User $user, string $action, ?string $oldValue, ?string $newValue): void
{
    TicketActivity::create([
        'ticket_id' => $ticket->id,
        'user_id' => $user->id,
        'action' => $action,
        'old_value' => $oldValue,
        'new_value' => $newValue,
    ]);
}
```

**Every action name actually recorded by the code:**

| `action` | Recorded in | old_value → new_value |
|---|---|---|
| `created` | `createTicket()` | NULL → `Open` |
| `updated` | `updateTicket()` (subject/description/category changed) | NULL → `Changed: Subject, Description, Category` (the changed ones) |
| `priority_changed` | `updateTicket()` | `Medium` → `Critical` |
| `due_date_changed` | `updateTicket()` (with priority change) | old date → new date (`d M Y, h:i A`) |
| `assigned` | `performAssignment()` (first assignment) | NULL → agent name |
| `status_changed` | `performAssignment()` (Open → Assigned) | `Open` → `Assigned` |
| `status_changed` | `startWork()` | `Assigned` → `In Progress` |
| `reassigned` | `performAssignment()` (agent already set) | old agent name → new agent name |
| `resolved` | `resolve()` | `In Progress` → `Resolved` |
| `reopened` | `reopen()` | `Previous resolution: <note>` → `In Progress` |
| `closed` | `close()` | `Resolved` → `Closed` |

Not logged: comments (they are their own table), user changes, category changes, notifications read.

- Activities are written **inside** the same transaction as the change, so a ticket change and its history
  cannot disagree.
- `old_value`/`new_value` are readable **text** (labels, names), not ids. Simple to display; the trade-off is
  that renaming an agent does not update old history (which is usually what an audit wants).

**7–8. DB state.** See the examples in sections 3, 5, 9, 11 and 13.

**9. Equivalent SQL.** `INSERT INTO ticket_activities (ticket_id, user_id, action, old_value, new_value, created_at, updated_at) VALUES (...);`

**10. Edge cases.** Same-agent assignment and unchanged edit → no activity. A label missing from
`ACTION_LABELS` falls back to the raw action name (`actionLabel()`).

**11. Error handling.** If the insert fails, the whole transaction (including the ticket change) rolls back.

**12. Interview questions.** Why store text instead of foreign keys? Why inside the transaction? How would
you log comments too?

**13. How to modify.** New action: call `recordActivity()` with a new name and add a label to
`TicketActivity::ACTION_LABELS`.

---

## 17. Dashboard statistics

**1. Business requirement.** One `/dashboard` URL shows a different dashboard per role, with real database
numbers. Admin stats are cached for 60 seconds.

**2. Realistic scenario.** The admin opens the dashboard in the morning to see status counts, overdue count,
average resolution time and agent workload.

**3. Input data.** `GET /dashboard`.

**4. Expected result.**

| Dashboard | Numbers | Cached? |
|---|---|---|
| Admin | total, per status, per priority, per category, 14-day creation trend, average resolution hours | Yes — key `dashboard:admin:stats`, 60 s |
| Admin | overdue count, 8 recent tickets, agent workload table (active/overdue/resolved per agent) | No (always live) |
| Agent | assigned total, pending (assigned), in progress, resolved/closed, overdue; 8 recent; 5 most overdue | No |
| Customer | total, active, resolved (awaiting confirmation), closed; 8 recently updated | No |

**5. Actual implementation.** `DashboardController::index()` → `adminDashboard()` /
`buildAdminStatistics()`, `agentDashboard()`, `customerDashboard()`.

**6. Code explanation.**

```php
$stats = Cache::remember(TicketService::DASHBOARD_CACHE_KEY, self::ADMIN_STATS_TTL_SECONDS, function () {
    return $this->buildAdminStatistics();
});
```

Average resolution time (simplified rule):

```php
$averageMinutes = Ticket::whereIn('status', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
    ->whereNotNull('resolved_at')
    ->value(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))'));

$averageResolutionHours = $averageMinutes !== null ? round($averageMinutes / 60, 1) : null;
```

- It measures **created_at → the latest `resolved_at`**. Because reopening clears `resolved_at` and a later
  resolve sets a new one, a reopened-and-re-resolved ticket counts its full time from creation to the final
  resolution. Time spent "waiting for the customer" is not subtracted. This is a deliberate simplification.
- `TIMESTAMPDIFF` is MySQL/MariaDB-specific (the only DB-specific SQL with `DATE()`).
- Status and priority counts use `GROUP BY` + `pluck('total', 'status')`, then loop over `Ticket::STATUSES`
  so statuses with zero tickets still appear as 0.

**7–8. DB state.** Read-only, except the `cache` table row for `dashboard:admin:stats` on a cache miss.

**9. Equivalent SQL.**

```sql
SELECT status, COUNT(*) AS total FROM tickets GROUP BY status;
SELECT priority, COUNT(*) AS total FROM tickets GROUP BY priority;
SELECT categories.name, (SELECT COUNT(*) FROM tickets WHERE categories.id = tickets.category_id) AS tickets_count
FROM categories ORDER BY name;
SELECT DATE(created_at) AS day, COUNT(*) AS total FROM tickets WHERE created_at >= ? GROUP BY day;
SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) FROM tickets
WHERE status IN ('resolved','closed') AND resolved_at IS NOT NULL LIMIT 1;
```

**10. Edge cases.**
- No resolved tickets → average `null` (the view shows a placeholder).
- Agent dashboard counts only `assigned_to = agent`, but the stat-card links open `tickets.index`, whose list
  for an agent is "created by me OR assigned to me" — so the list can show more rows than the card if the agent
  also created tickets.
- Stats may be up to 60 seconds old only if a change happens without cache invalidation; all ticket and
  category writes clear it (§21).

**11. Error handling.** None specific.

**12. Interview questions.** What is cache-aside? Why is overdue not cached? Weakness of the average
resolution formula? Why would `TIMESTAMPDIFF` break on SQLite/PostgreSQL?

**13. How to modify.** New stat: add a key to the array in `buildAdminStatistics()` and display it in
`resources/views/dashboard/admin.blade.php`. Change TTL: `ADMIN_STATS_TTL_SECONDS`. Trend length: `TREND_DAYS`.

---

## 18. Database transactions and row locking

**1. Business requirement.** Coordinated changes (ticket + activity + notification) succeed or fail
together. Two people must not change the same ticket at the same time in conflicting ways.

**2. Realistic scenario.** Two admins click **Assign** on the same Open ticket at the same second, one
choosing Sarah, one David.

**3. Input data.** Two concurrent `POST /tickets/33/assign` requests.

**4. Expected result.** The first request locks the row, assigns Sarah, commits. The second waits, then reads
the **fresh** row (status Assigned, agent Sarah) and performs a **reassignment** to David (activity
`reassigned`). There is never a half-written state.

**5. Actual implementation.** `DB::transaction()` in every write method of `TicketService`
(`createTicket`, `updateTicket`, `assignAgent`, `autoAssign`, `startWork`, `resolve`, `reopen`, `close`,
`addComment`) and private `lockTicket()` in all except `createTicket` and `updateTicket`.

**6. Code explanation.**

```php
private function lockTicket(Ticket $ticket): Ticket
{
    return Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
}
```

- `lockForUpdate()` adds `FOR UPDATE`. In InnoDB, other transactions that try to lock the same row **wait**
  until this transaction commits or rolls back.
- The lock is released automatically at COMMIT/ROLLBACK, which is why it **must** be inside
  `DB::transaction()` (outside a transaction it would be released immediately).
- The method returns a **fresh** model, and all rule checks are done on that fresh copy, not on the
  route-bound `$ticket` (which could be stale).
- `DB::transaction(fn)`: begins, runs the closure, commits if it returns, **rolls back and re-throws** if it
  throws. The closure's return value is returned (that is how `assignAgent()` gets `$wasChanged`).
- Database notifications are inserted inside the transaction (rolled back together). Emails are queued only
  after commit (§20). Cache is cleared after the transaction returns.

**7. DB state before.** ticket 33 `open`, `assigned_to` NULL.

**8. DB state after (both requests).** `assigned_to = 3 (David)`, status `assigned`; activities:
`assigned` (Sarah), `status_changed`, `reassigned` (Sarah → David); notifications for both agents.

**9. Equivalent SQL.**

```sql
-- Request A                                      -- Request B
START TRANSACTION;                                START TRANSACTION;
SELECT * FROM tickets WHERE id=33 FOR UPDATE;     SELECT * FROM tickets WHERE id=33 FOR UPDATE;  -- waits...
UPDATE tickets SET assigned_to=2, status='assigned' ...;
INSERT INTO ticket_activities ...;
COMMIT;                                           -- ...now gets the row: status='assigned', assigned_to=2
                                                  UPDATE tickets SET assigned_to=3 ...;   -- reassignment
                                                  COMMIT;
```

**10. Edge cases and limitations.**
- Different tickets are not protected against picking the same agent (§6).
- `updateTicket()` uses a transaction but **no row lock**; two simultaneous edits → last write wins.
- `createTicket()` does not need a lock (new row).
- A lock wait that takes too long raises a lock-wait-timeout `QueryException` (InnoDB default 50 s).
- Test: `QueueAndEmailTest::test_no_email_job_is_dispatched_when_the_transaction_rolls_back` proves that a
  rollback removes the ticket change, the activities and the notifications, and that no email job is pushed.

**11. Error handling.** Any exception inside the closure → rollback → exception continues to Laravel's
handler (validation → redirect back; other → 500).

**12. Interview questions.** What is a transaction? What does `FOR UPDATE` do? Why must the lock be inside a
transaction? What is "lost update"? Why are emails not sent inside the transaction? Optimistic vs pessimistic
locking?

**13. How to modify.** Add locking to edits: in `updateTicket()`, call `$this->lockTicket($ticket)` inside the
transaction and apply the changes to the locked copy.

> **বাংলায়:** Transaction মানে "সব নয়তো কিছুই না" — ticket update, activity, notification তিনটাই হবে, অথবা কোনোটাই
> হবে না (rollback)। Row locking (`FOR UPDATE`) মানে একজন যখন ticket-টা নিয়ে কাজ করছে, অন্যজন একই row lock করতে
> চাইলে অপেক্ষা করবে। Lock শুধু transaction শেষ (commit/rollback) হলে ছাড়ে, তাই lock সবসময় transaction-এর ভিতরে
> দিতে হয়।

---

## 19. Notifications (matrix as implemented)

**1. Business requirement.** Relevant people get an in-app notification (and some get an email) for ticket
events, without refreshing the page.

**2. Realistic scenario.** Admin assigns a ticket; within ~5 seconds Sarah's bell badge increases and a
toast appears.

**3. Input data.** Triggered by ticket operations.

**4. Expected result — matrix as implemented in `TicketService`:**

| Event (`event_type`) | Method | Recipient | In-app | Queued email |
|---|---|---|---|---|
| `created` | `createTicket()` | Every active admin, except the creator | Yes | Yes |
| `assigned` | `performAssignment()` | Assigned agent | Yes | Yes |
| `reassigned` | `performAssignment()` | New agent only | Yes | Yes |
| `comment` | `addComment()` | Creator and/or assigned agent, except author | Yes | No |
| `resolved` | `resolve()` | Ticket creator | Yes | Yes |
| `reopened` | `reopen()` | Assigned agent | Yes | Yes |
| `closed` | `close()` | Assigned agent | Yes | No |
| (none) | `updateTicket()`, `startWork()` | — | No | No |

The `created` row was added at the project owner's request, beyond the original MASTER_PROMPT §9.2 matrix.

Common filter in `notifyUser()`: skip if the recipient is `null`, **inactive**, or **the actor**
(no self-notification). No notification when nothing changed (same-agent assignment returns early).

**5. Actual implementation.** `TicketService::notifyUser()` →
`$recipient->notify(new TicketNotification(...))` (`app/Notifications/TicketNotification.php`,
channel `database`, **not queued**). Reading: `NotificationController` + `public/js/notifications.js`.

**6. Code explanation.**

```php
private function notifyUser(?User $recipient, Ticket $ticket, User $actor, string $eventType, string $title, string $message, bool $sendEmail): void
{
    if ($recipient === null || ! $recipient->is_active) {
        return;
    }
    if ($recipient->id === $actor->id) {
        return; // Do not notify people about their own actions.
    }
    $recipient->notify(new TicketNotification($ticket, $eventType, $title, $message));
    if ($sendEmail) {
        $this->queueEmail($recipient, $ticket, $title, $message);
    }
}
```

`TicketNotification::toArray()` stores `ticket_id`, `ticket_number`, `title`, `message`, `event_type` as JSON
in `notifications.data`. Because it is not queued, in-app notifications work even with no queue worker
(`QueueAndEmailTest::test_in_app_notification_works_without_a_queue_worker`).

**7. DB state before.** `notifications` empty for user 2.

**8. DB state after.**

| id (UUID) | notifiable_type | notifiable_id | data | read_at |
|---|---|---|---|---|
| 9b1e… | App\Models\User | 2 | `{"ticket_id":33,"ticket_number":"TK-000033","title":"New ticket assigned","message":"Ticket TK-000033 \"Cannot access billing portal\" has been assigned to you.","event_type":"assigned"}` | NULL |

**9. Equivalent SQL.** Insert above; reading:
`SELECT * FROM notifications WHERE notifiable_type = 'App\\Models\\User' AND notifiable_id = 2 ORDER BY created_at DESC LIMIT 5;`
(`notifiable_type`/`notifiable_id` is a **polymorphic** relation from the `Notifiable` trait.)

**10. Edge cases.** Inactive agent → no notification. Polling is near-real-time (5 s visible tab, 30 s
hidden), not push. Details: [Notification system guide](13-NOTIFICATION-SYSTEM-GUIDE.md).

**11. Error handling.** A failed notification insert rolls back the whole operation (it is inside the
transaction). Someone else's notification id → 404 (`notifications()->findOrFail($id)`).

**12. Interview questions.** Why database channel instead of WebSockets? Why not queue the notification?
How do you prevent users reading others' notifications?

**13. How to modify.** New event: call `notifyUser()` with a new `event_type`, then update this matrix.
Change recipients per event in the corresponding service method.

> **বাংলায়:** `notifications` টেবিলে `notifiable_type` + `notifiable_id` দুইটা column মিলে বলে notification-টা
> কার — এটাকে polymorphic relation বলে (একই টেবিল অন্য model-এর জন্যও ব্যবহার করা যেত)। Notification queue-তে না
> পাঠিয়ে সরাসরি insert করা হয়, তাই queue worker বন্ধ থাকলেও bell-এ notification আসে।

---

## 20. Email job dispatch after commit

**1. Business requirement.** Emails are sent by a queued job, **only after the database commit**, and an
email problem must never undo or hide a committed ticket change.

**2. Realistic scenario.** Admin assigns a ticket while the queue worker is stopped. The assignment succeeds
immediately; the email waits in the `jobs` table until `php artisan queue:work` runs.

**3. Input data.** Recipient email/name + small array of ticket data.

**4. Expected result.** On commit → one `jobs` row. On rollback → no job. If pushing the job fails → error
logged, user still sees success.

**5. Actual implementation.** `TicketService::queueEmail()` → `DB::afterCommit()` →
`SendTicketEmail::dispatch()` (`app/Jobs/SendTicketEmail.php`: `$tries = 3`, `$backoff = 10`, `failed()`)
→ `app/Mail/TicketUpdateMail.php` → `resources/views/emails/ticket-update.blade.php`.

**6. Code explanation.**

```php
DB::afterCommit(function () use ($recipientEmail, $recipientName, $emailData) {
    try {
        SendTicketEmail::dispatch($recipientEmail, $recipientName, $emailData);
    } catch (Throwable $exception) {
        Log::error('Could not queue ticket email', [
            'recipient' => $recipientEmail,
            'ticket_number' => $emailData['ticket_number'],
            'error' => $exception->getMessage(),
        ]);
    }
});
```

- `DB::afterCommit()` holds the closure until the **outermost** transaction commits. On rollback, the closure
  is discarded.
- Only plain values are passed (not the model), so the job payload is small and stable.
- The job runs in the worker process, outside the web request and outside any transaction (SMTP never holds
  a DB lock).

**7. DB state before.** `jobs` empty.

**8. DB state after commit.**

| jobs.queue | attempts | payload (contains) |
|---|---|---|
| default | 0 | `"displayName":"App\\Jobs\\SendTicketEmail"` |

After `queue:work` succeeds, the row is deleted. After 3 failures, it moves to `failed_jobs` and
`failed()` logs "Ticket email failed permanently".

**9. Equivalent SQL.** `INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at) VALUES ('default', '{...}', 0, NULL, ..., ...);`

**10. Edge cases.** `MAIL_MAILER=log` by default → the email is written to `storage/logs/laravel.log`.
Email failure does not reverse assignment (`QueueAndEmailTest::test_email_failure_does_not_reverse_the_committed_assignment`).

**11. Error handling.** Dispatch failure: `Could not queue ticket email` in the log. Final job failure:
`Ticket email failed permanently` in the log.

**12. Interview questions.** Why after commit? What if the worker is down? What are `$tries`/`$backoff`?
What is the outbox pattern, and why is it not used here? (Future improvement only.)

**13. How to modify.** More retries: change `$tries`. Different queue: `SendTicketEmail::dispatch(...)->onQueue('emails')`
and run `queue:work --queue=emails`. Details: [Queue and email guide](14-QUEUE-AND-EMAIL-GUIDE.md).

> **বাংলায়:** Email পাঠানো ধীর আর ব্যর্থ হতে পারে, তাই এটা job হিসেবে `jobs` টেবিলে রাখা হয় আর worker পরে পাঠায়।
> `DB::afterCommit()` নিশ্চিত করে যে transaction সফলভাবে commit হলেই job তৈরি হবে — rollback হলে "ভুয়া" email যাবে
> না। আর job তৈরি করতে সমস্যা হলে শুধু log হয়, কারণ ticket-এর পরিবর্তন তো আগেই save হয়ে গেছে।

---

## 21. Cache invalidation

**1. Business requirement.** Cached data must not show wrong information after a change.

**2. Realistic scenario.** Admin views the dashboard (cached), then a customer creates a ticket. On the
admin's next dashboard load, "Total tickets" includes the new ticket.

**3. Input data.** Any write.

**4. Expected result.**

| Cache key | Built by | TTL | Cleared by |
|---|---|---|---|
| `dashboard:admin:stats` (`TicketService::DASHBOARD_CACHE_KEY`) | `DashboardController::adminDashboard()` | 60 s | `TicketService::clearDashboardCache()` after create, update (if changed), assign/auto-assign (if changed), start, resolve, reopen, close; `CategoryController::clearCaches()` |
| `ticket:active-categories` (`Category::ACTIVE_CACHE_KEY`) | `Category::activeForDropdown()` | 3600 s | `Category::clearActiveCache()` via `CategoryController::clearCaches()` (store/update/destroy) |

Not cleared by comments (comments are not in the stats). Not cached at all: overdue count, recent tickets,
agent workload, agent/customer dashboards, ticket lists, notifications.

**5. Actual implementation.** See table. Store: `CACHE_STORE=database` (the `cache` table).

**6. Code explanation.**

```php
public function clearDashboardCache(): void
{
    Cache::forget(self::DASHBOARD_CACHE_KEY);
}
```

- Called **after** `DB::transaction()` returns, so the cache is only cleared when the commit succeeded
  (otherwise a reader could rebuild the cache from not-yet-committed data in theory — and a rollback would
  make clearing pointless).
- Pattern: **cache-aside** (read with `Cache::remember`, invalidate with `Cache::forget` on write; the next
  read rebuilds).
- The category cache is **display-only**: validation always checks the `categories` table.

**7. DB state before.** `cache` table has a row for `<prefix>dashboard:admin:stats`.

**8. DB state after.** The row is deleted; the next admin dashboard request recreates it.

**9. Equivalent SQL.** `DELETE FROM cache WHERE key = '<prefix>dashboard:admin:stats';`

**10. Edge cases.** User activation changes do not clear the dashboard cache (the cached stats contain no
user data; the agent workload table is not cached). Tests: `CacheTest.php` (all eight tests).

**11. Error handling.** None; `Cache::forget` on a missing key is harmless.

**12. Interview questions.** Cache-aside vs write-through? Why not cache overdue? Why a TTL when you also
invalidate? (Safety net.) What would change with Redis? (Only `CACHE_STORE`; Redis is **not** used here.)

**13. How to modify.** Cache something new: wrap the query in `Cache::remember('key', ttl, fn)` and add
`Cache::forget('key')` wherever the data changes. Details: [Caching guide](15-CACHING-GUIDE.md).

> **বাংলায়:** Cache-aside মানে: আগে cache-এ খুঁজি; না পেলে database থেকে এনে cache-এ রাখি। Data বদলালে cache মুছে
> দিই (`Cache::forget`), পরের request আবার নতুন করে বানায়। মোছাটা transaction commit-এর পরে করা হয়, যাতে শুধু সফল
> পরিবর্তনের পরেই cache খালি হয়।

---

## 22. Permission checks

**1. Business requirement.** Authorization is enforced on the backend for every protected action; hiding
buttons is not enough.

**2. Realistic scenario.** Customer Emily changes the URL to `/tickets/5` (John's ticket) → 403. Agent David
posts directly to `/tickets/5/assign` with a tool like curl → 403.

**3. Input data.** Any request.

**4. Expected result.** Four layers:

| Layer | What it checks | Code |
|---|---|---|
| 1. `auth` middleware | Logged in? Else redirect to `/login` (`bootstrap/app.php` `redirectGuestsTo`) | Laravel built-in |
| 2. `active` middleware | `is_active`? Else logout + redirect / 401 JSON | `ActiveUserMiddleware` |
| 3. `role:admin` middleware | Role for admin-only routes (assign, auto-assign, users, categories) | `RoleMiddleware` |
| 4. Policy | This user + this ticket + current status | `TicketPolicy` via `Gate::authorize()` in controllers, `@can` in Blade |
| + | Own data only | `Ticket::scopeVisibleTo()` for lists; `$request->user()->notifications()` for notifications |
| + | CSRF | `web` middleware group; every form has `@csrf`; JS sends `X-CSRF-TOKEN` |

**5. Actual implementation.** `routes/web.php`, `app/Http/Middleware/*`, `app/Policies/TicketPolicy.php`,
`TicketController`, `TicketCommentController`.

**6. Code explanation.**

| Policy method | Rule |
|---|---|
| `view` | admin, or creator, or assigned agent |
| `create` | everyone (active) |
| `update` | not closed AND (admin OR (creator AND status open AND unassigned)) |
| `assign` | admin AND status in open/assigned/in_progress |
| `start` | (admin OR assigned agent) AND `canPerform('start')` |
| `resolve` | (admin OR assigned agent) AND `canPerform('resolve')` |
| `close` | (admin OR creator) AND `canPerform('close')` |
| `reopen` | (admin OR creator) AND `canPerform('reopen')` |
| `comment` | `view` AND not closed |

```php
public function view(User $user, Ticket $ticket): bool
{
    if ($user->isAdmin()) { return true; }
    return $this->isCreator($user, $ticket) || $this->isAssignedAgent($user, $ticket);
}
```

- Laravel finds `TicketPolicy` automatically from the model name (`App\Models\Ticket` →
  `App\Policies\TicketPolicy`).
- `created_by`/`assigned_to` are cast to `integer` in `Ticket::casts()`, so `===` comparisons with
  `$user->id` work.

**7–8. DB state.** Unchanged on denial.

**9. Equivalent SQL.** List visibility for non-admins: `WHERE (created_by = ? OR assigned_to = ?)`.

**10. Edge cases.** An agent who created a ticket is its "creator" (can close/reopen it). Notification links
to a ticket you lost access to (e.g. reassigned) → 403 page with a hint. User management and category
routes have no policy — `role:admin` is enough there.

**11. Error handling.** Policy: 403 "This action is unauthorized."; role middleware: 403
"You do not have permission to access this page."; missing CSRF: 419 page (`resources/views/errors/419.blade.php`).

**12. Interview questions.** Middleware vs policy? Why check in both Blade and controller? How is a customer
prevented from seeing another customer's ticket in the list AND via URL? (Tests: `AuthorizationTest.php`.)

**13. How to modify.** Let agents assign: move the two assign routes out of the `role:admin` group and change
`TicketPolicy::assign()` to `($user->isAdmin() || $user->isAgent()) && ...`.

> **বাংলায়:** Middleware হলো দরজার পাহারাদার — "তুমি logged in? active? admin?" — পুরো route group-এর জন্য।
> Policy হলো নির্দিষ্ট জিনিসের নিয়ম — "এই user কি এই ticket-এ এই কাজ করতে পারে?" Blade-এ `@can` শুধু button
> লুকায়; আসল নিরাপত্তা controller-এর `Gate::authorize()`, কারণ কেউ চাইলে button ছাড়াই সরাসরি request পাঠাতে পারে।

---

## 23. Known limitations and future improvements

### Known limitations (implemented behaviour, documented on purpose)

1. **Simultaneous auto-assign of different tickets** may pick the same least-busy agent. The row lock is on
   the ticket, not on the agent's workload. No distributed lock is used.
2. **Resolved tickets cannot be reassigned**, so an agent with Resolved tickets can only be deactivated (or
   change role) after those tickets are **closed**.
3. **Reopen keeps the original SLA**; a reopened ticket can be overdue immediately.
4. **Average resolution time** = `created_at` → latest `resolved_at`; reopen periods are not separated.
5. **Workload distribution is application-level** (people), not infrastructure load balancing (servers).
6. `updateTicket()` has no row lock (last write wins on simultaneous edits).
7. Notifications are near-real-time via polling (up to 5 s delay; 30 s when the tab is hidden).
8. SLA uses continuous calendar hours (no business hours/holidays).

### Future improvement: SLA escalation with Laravel Scheduler (NOT implemented)

Today, overdue is only **calculated** when someone looks. Nothing happens automatically when a deadline
passes. A future version could:

1. Create an Artisan command, e.g. `php artisan make:command EscalateOverdueTickets`, whose `handle()` runs
   `Ticket::overdue()->with('assignedAgent')->get()` and notifies the agent and admins (using a new
   `event_type` like `overdue`), remembering which tickets were already escalated (for example a new nullable
   `escalated_at` column) so it does not notify every minute.
2. Schedule it in `routes/console.php`: `Schedule::command('tickets:escalate-overdue')->everyFiveMinutes();`
3. Run the scheduler on the server: one cron entry `* * * * * php artisan schedule:run`
   (on Windows: Task Scheduler, or `php artisan schedule:work` during development).

Other future ideas (also **not** implemented): WebSockets/Reverb or SSE instead of polling, Redis for cache
and queue, an outbox table for guaranteed email delivery, business-hours SLA calendars, locking the agent
row during auto-assign.

> **বাংলায়:** Laravel Scheduler হলো "নির্দিষ্ট সময় পর পর একটা কাজ চালাও"-এর ব্যবস্থা। Server-এ প্রতি মিনিটে একবার
> `schedule:run` চলে, আর Laravel ঠিক করে কোন command এখন চালানোর সময়। ভবিষ্যতে এটা দিয়ে প্রতি ৫ মিনিটে overdue
> ticket খুঁজে agent/admin-কে জানানো যাবে। এই project-এ এটা এখনো **তৈরি করা হয়নি** — interview-এ এটা "future
> improvement" হিসেবে বলবে।
