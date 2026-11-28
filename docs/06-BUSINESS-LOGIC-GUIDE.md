# 06 — Business Logic Guide

This guide explains **every business rule** in ResolveDesk: what the rule is, where the code lives, what
happens in the database, and how to change it during an interview.

**Updated for V2** (multi-project customer support). Sections 1-22 are the original rules, corrected where V2
changed them (assignment eligibility, previous-agent notification, customer-reply email, optimistic locking,
system activities). Sections 24-32 are the new V2 rules. The six most important V2 operations are explained in
the 30-question format in [22 — V2 business features](22-V2-BUSINESS-FEATURES.md#15-thirty-question-walkthroughs).

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
| Coordinated ticket operations (create, edit, comments, lifecycle, SLA) | `app/Services/TicketService.php` |
| V2: assignment, eligibility, assignment lock | `app/Services/TicketAssignmentService.php` + `Ticket::canBeAssignedTo()` |
| V2: notifications, broadcast, email dispatch | `app/Services/TicketNotifier.php` |
| V2: project ↔ client / agent associations | `app/Services/ProjectService.php` |
| V2: attachment storage | `app/Services/AttachmentService.php` + `config/resolvedesk.php` |
| V2: ticket scope rules | `TicketService::resolveScope()` |
| V2: create-ticket input format | `app/Http/Requests/StoreTicketRequest.php` |
| V2: SLA reminders | `app/Console/Commands/CheckSlaBreaches.php` + `routes/console.php` |
| V2: reports / CSV | `app/Http/Controllers/ReportController.php` + `TicketController::applyFilters()` |
| V2: rate limits | `AppServiceProvider::configureRateLimiting()` |
| WHO may do an action on a ticket | `app/Policies/TicketPolicy.php` |
| Role-only pages (admin) | `app/Http/Middleware/RoleMiddleware.php` via `role:admin` in `routes/web.php` |
| Inactive users blocked | `app/Http/Controllers/AuthController.php::login()` + `app/Http/Middleware/ActiveUserMiddleware.php` |
| User rules (self, last admin, agent with tickets) | `app/Http/Controllers/UserController.php::ensureRoleOrStatusChangeIsAllowed()`; V2 client rule `ensureClientChangeIsAllowed()` |
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

V2 adds a third kind: **stale data**. `App\Exceptions\TicketConflictException` (version mismatch, or a reused
`request_token` with different content) → normal form: redirect back **with the typed input** and an error
flash; JSON: HTTP **409**. And rate limits → HTTP **429**.

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
24. [Projects and client organizations (V2)](#24-projects-and-client-organizations-v2)
25. [Customer to client mapping (V2)](#25-customer-to-client-mapping-v2)
26. [Project associations and removal guards (V2)](#26-project-associations-and-removal-guards-v2)
27. [Ticket scope and role-specific creation (V2)](#27-ticket-scope-and-role-specific-creation-v2)
28. [Internal notes (V2)](#28-internal-notes-v2)
29. [Secure attachments (V2)](#29-secure-attachments-v2)
30. [SLA breach reminders (V2)](#30-sla-breach-reminders-v2)
31. [Idempotency and optimistic locking (V2)](#31-idempotency-and-optimistic-locking-v2)
32. [Reports and CSV export (V2)](#32-reports-and-csv-export-v2)

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

**1. Business requirement.** Any active user can create a ticket (except a customer whose client organization is
inactive). It starts Open, unassigned, `version` 1, with an SLA deadline and an activity record. The creator is
always the logged-in user. V2: the ticket gets a **scope** (project and/or client, chosen by role-specific rules,
section 27), optional **attachments** (section 29), and a **request token** so a double submit creates only one
ticket (section 31). Every active administrator is told about the new ticket (in-app + queued email). This admin
alert was added at the project owner's request, beyond the original MASTER_PROMPT §9.2 matrix.

**2. Realistic scenario.** Customer John Carter (client ACME) reports that HRMS shows negative leave balances:
subject "Leave balance shows negative days", category "Software Bug", priority High, project HRMS.

**3. Input data.** `POST /tickets` (`multipart/form-data`), validated by `app/Http/Requests/StoreTicketRequest.php`:

| Field | Rule |
|---|---|
| `subject` | `required`, `string`, `max:255` |
| `description` | `required`, `string`, `max:5000` |
| `category_id` | `required`, `integer`, exists in `categories` with `is_active = true` |
| `priority` | `required`, `Rule::in(array_keys(Ticket::PRIORITIES))` |
| `project_id` (V2) | `nullable`, `integer`, `exists:projects,id` (business rules in `resolveScope()`) |
| `client_id` (V2) | `nullable`, `integer`, `exists:clients,id` (ignored for customers and agents) |
| `request_token` (V2) | `required`, `string`, `uuid` (hidden field, new UUID per form) |
| `attachments[]` (V2) | max 5 files, each ≤ 5120 KB, extension + detected MIME in pdf/png/jpg/jpeg/txt/docx/xlsx |

**4. Expected result.** Redirect to `tickets.show` with "Ticket TK-000041 was created successfully.";
status Open; `project_id` = HRMS, `client_id` = ACME (from John's account); `due_at` = created_at + 8 hours;
one `created` activity; one "New ticket created" notification + one queued email per active admin; dashboard
cache cleared.

**5. Actual implementation.** `TicketController::store(StoreTicketRequest $request)` →
`TicketService::createTicket($data, $creator, $files)` → `resolveScope()`, `AttachmentService`,
`TicketNotifier::notifyAdmins()`. Authorization: `StoreTicketRequest::authorize()` →
`TicketPolicy::create()` (false for a customer of an inactive client). Route middleware adds
`throttle:ticket-create` (10/min).

**6. Code explanation.** (Full block-by-block in [05 — 4.1](05-MODULE-WISE-CODE-EXPLANATION.md#41-operation-create-a-ticket).)

```php
$ticket = DB::transaction(function () use ($data, $creator, $requestToken, $storedFiles) {
    [$projectId, $clientId] = $this->resolveScope($creator, $data['project_id'] ?? null, $data['client_id'] ?? null);
    $createdAt = now();

    $ticket = new Ticket;
    $ticket->fill($data);                          // subject, description, category_id, priority
    $ticket->project_id = $projectId;              // validated scope, never raw input
    $ticket->client_id = $clientId;
    $ticket->created_by = $creator->id;            // From the logged-in user, never from the form.
    $ticket->assigned_to = null;
    $ticket->status = Ticket::STATUS_OPEN;
    $ticket->version = 1;
    $ticket->request_token = $requestToken;
    $ticket->created_at = $createdAt;
    $ticket->due_at = Ticket::calculateDueAt($createdAt, $ticket->priority);
    $ticket->save();                               // INSERT -> the database generates the id.

    $ticket->ticket_number = Ticket::makeTicketNumber($ticket->id);
    $ticket->save();

    TicketActivity::record($ticket, $creator, 'created', null, 'Open');
    ...
    $this->notifier->notifyAdmins($ticket, $creator, 'created',
        'New ticket created',
        "{$creator->name} created ticket {$ticket->ticket_number} \"{$ticket->subject}\".",
        true);

    return $ticket;
}, 3);
```

- `fill($data)` + `$fillable` = **mass-assignment protection**: `status`, `created_by`, `project_id`,
  `client_id`, `version` posted by a hacker are ignored; the trusted values are set explicitly.
- `resolveScope()` returns the trusted scope (a customer's `client_id` is always their own).
- `created_at` is set explicitly so that `due_at` is calculated from exactly the same time.
- Everything is in one transaction; files are written before it and deleted if it fails.
- `notifyAdmins(..., true)`: in-app notification inside the transaction; broadcast + email after commit.
  Inactive admins and an admin creator are skipped.
- Cache is cleared **after** the transaction.

**7. DB state before.** `tickets` last id 40; no ticket with `created_by = 6` and this `request_token`.

**8. DB state after.**

| tickets.id | ticket_number | project_id | client_id | created_by | assigned_to | status | priority | version | due_at |
|---|---|---|---|---|---|---|---|---|---|
| 41 | TK-000041 | 1 (HRMS) | 1 (ACME) | 6 (John) | NULL | open | high | 1 | 2026-11-26 18:00:00 |

| ticket_activities.ticket_id | user_id | action | old_value | new_value | is_internal |
|---|---|---|---|---|---|
| 41 | 6 | created | NULL | Open | 0 |

| notifications.notifiable_id | data.event_type | data.title |
|---|---|---|
| 1 (Nadia, admin) | created | New ticket created |

`jobs`: one `SendTicketEmail` row for Nadia (pushed after COMMIT).

**9. Equivalent SQL.** See [22 — 15.1](22-V2-BUSINESS-FEATURES.md#151-create-a-project-ticket-as-a-customer)
(replay check, scope checks with `LOCK IN SHARE MODE`, INSERT, number UPDATE, activity, notifications, COMMIT,
after-commit job and cache delete).

**10. Edge cases.** Inactive category from a stale page → rejected. Standalone customer choosing a project →
rejected. Double click → one ticket (replay message). An agent who created a ticket later acts as its
**creator** (close/reopen). Agents are not notified on creation (nobody is assigned yet).

**11. Error handling.** Standard validation messages plus `Please choose an active category.`,
`The form expired. Please reload the page and try again.` (missing token), the scope messages of section 27,
the conflict message for a reused token with different content, 429 for too many submissions. A failed insert
rolls back and deletes stored files.

**12. Interview questions.** Why not trust `created_by` / `client_id` from the form? Why a transaction? Why are
files written before the transaction? How does `request_token` prevent duplicates even with two parallel
requests? (Tests: `TicketManagementTest::test_customer_can_create_a_ticket`, `TicketScopeTest`, `ConsistencyTest`,
`QueueAndEmailTest::test_new_ticket_notifies_and_emails_every_active_admin`.)

**13. How to modify.** New required field: migration → `$fillable` → `StoreTicketRequest` + `update()` rules →
`resources/views/tickets/_form.blade.php`. Admin alert in-app only: change the last `notifyAdmins()` argument in
`createTicket()` from `true` to `false`.

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

**1. Business requirement.** An admin chooses an **eligible** agent for a ticket. Open → Assigned. V2
eligibility: an active support agent, and for a **project** ticket the agent must support that project
(`project_user`); for a **global** ticket every active agent is eligible. The agent gets an in-app notification
(real-time + polling fallback) and a queued email.

**2. Realistic scenario.** Admin opens TK-000041 (HRMS, Open, version 1). The dropdown lists only HRMS agents:
"David Chen (1 active)", "Sarah Ahmed (2 active)". She selects Sarah and clicks **Assign**.

**3. Input data.** `POST /tickets/{ticket}/assign` with `agent_id` (`required`, `integer`, `exists:users,id`) and
V2 hidden `version` (`nullable`, `integer`).

**4. Expected result.** `assigned_to` = Sarah, status `assigned`, `version` 2, two activities (`assigned`,
`status_changed`), one `notifications` row for Sarah, broadcast + `SendTicketEmail` job after commit, dashboard
cache cleared, flash "Ticket assigned to Sarah Ahmed.".

**5. Actual implementation.**
- Route middleware: `auth`, `active`, `role:admin`.
- `TicketController::assign()` → `Gate::authorize('assign', $ticket)` (`TicketPolicy::assign()`: admin AND
  status in `ACTIVE_STATUSES`).
- V2 `TicketAssignmentService::assignAgent()` → `withAssignmentLock()` (`Cache::lock`) → `DB::transaction(..., 3)`
  → `lockProjectAndTicket()` → `ensureVersion()` → private `performAssignment()` → `Ticket::canBeAssignedTo()`,
  `TicketActivity::record()`, `TicketNotifier::notify()`; then `Cache::forget(TicketService::DASHBOARD_CACHE_KEY)`.

**6. Code explanation.**

```php
public function assignAgent(Ticket $ticket, User $agent, User $admin, ?int $expectedVersion = null): bool
{
    $wasChanged = $this->withAssignmentLock(function () use ($ticket, $agent, $admin, $expectedVersion) {
        // DB::transaction(..., 3): retry up to 3 times if MySQL reports a deadlock.
        return DB::transaction(function () use ($ticket, $agent, $admin, $expectedVersion) {
            $lockedTicket = $this->lockProjectAndTicket($ticket);
            $lockedTicket->ensureVersion($expectedVersion);

            return $this->performAssignment($lockedTicket, $agent, $admin);
        }, 3);
    });

    if ($wasChanged) {
        Cache::forget(TicketService::DASHBOARD_CACHE_KEY);
    }

    return $wasChanged;
}
```

Inside `performAssignment()`:

```php
if (! in_array($ticket->status, Ticket::ACTIVE_STATUSES, true)) { throw ... }   // status re-check on locked row

// Re-read the agent WITH a row lock: never trust that the submitted user is still an eligible agent,
// and wait for a deactivation that is running right now (UserController locks the same row).
$agent = User::whereKey($agent->id)->lockForUpdate()->first();
if ($agent === null || ! $ticket->canBeAssignedTo($agent)) {
    $message = $ticket->project_id === null
        ? 'Please select an active support agent.'
        : 'Please select an active support agent who supports this ticket\'s project.';
    throw ValidationException::withMessages(['agent_id' => $message]);
}

if ($ticket->assigned_to === $agent->id) {
    return false;                                  // duplicate prevention, no version change
}

$previousAgent = $ticket->assigned_to ? User::find($ticket->assigned_to) : null;
$previousStatusLabel = $ticket->statusLabel();

$ticket->assigned_to = $agent->id;
if ($ticket->status === Ticket::STATUS_OPEN) {
    $ticket->status = Ticket::STATUS_ASSIGNED;     // Open -> Assigned happens through assignment.
}
$ticket->version = $ticket->version + 1;
$ticket->save();

if ($previousAgent === null) {
    TicketActivity::record($ticket, $admin, 'assigned', null, $agent->name);
    TicketActivity::record($ticket, $admin, 'status_changed', $previousStatusLabel, $ticket->statusLabel());
    $this->notifier->notify($agent, $ticket, $admin, 'assigned', 'New ticket assigned', "...", true);
} else {
    // reassignment, see section 7
}
return true;
```

- `exists:users,id` only proves the user exists. `canBeAssignedTo()` re-checks **role**, **is_active** and
  **project membership** on fresh data — so a customer id, an inactive agent, or an ERP-only agent on an HRMS
  ticket is rejected even if someone edits the HTML `<select>`.
- The last argument `true` of `notify()` means "also queue an email (after commit)".
- Locks: assignment cache lock → project row shared lock → ticket row `FOR UPDATE` (always this order, so no
  deadlocks with `ProjectService`, which locks the project row exclusively).

**7. DB state before.**

| tickets.id | project_id | assigned_to | status | version |
|---|---|---|---|---|
| 41 | 1 (HRMS) | NULL | open | 1 |

`project_user`: (1, 2 Sarah), (1, 3 David).

**8. DB state after.**

| tickets.id | assigned_to | status | version |
|---|---|---|---|
| 41 | 2 (Sarah) | assigned | 2 |

| ticket_activities.action | old_value | new_value |
|---|---|---|
| assigned | NULL | Sarah Ahmed |
| status_changed | Open | Assigned |

| notifications.notifiable_id | data (JSON, shortened) |
|---|---|
| 2 | `{"ticket_id":41,"ticket_number":"TK-000041","title":"New ticket assigned",...,"event_type":"assigned"}` |

`jobs`: one `SendTicketEmail` (Sarah). `cache_locks`: empty again after the request.

**9. Equivalent SQL.**

```sql
INSERT INTO cache_locks (`key`, owner, expiration) VALUES ('resolvedesk-cache-ticket-assignment', '<random>', <now+10>);
START TRANSACTION;
SELECT * FROM projects WHERE id = 1 LIMIT 1 LOCK IN SHARE MODE;
SELECT * FROM tickets WHERE id = 41 LIMIT 1 FOR UPDATE;
SELECT * FROM users WHERE id = 2 LIMIT 1;
SELECT EXISTS(SELECT * FROM project_user WHERE project_id = 1 AND user_id = 2) AS `exists`;
UPDATE tickets SET assigned_to = 2, status = 'assigned', version = 2, updated_at = NOW() WHERE id = 41;
INSERT INTO ticket_activities (...) VALUES (41, 1, 'assigned', NULL, 'Sarah Ahmed', 0, ...);
INSERT INTO ticket_activities (...) VALUES (41, 1, 'status_changed', 'Open', 'Assigned', 0, ...);
INSERT INTO notifications (...) VALUES ('<uuid>', ..., 2, '{...}', NULL, ...);
COMMIT;
DELETE FROM cache_locks WHERE `key` = 'resolvedesk-cache-ticket-assignment' AND owner = '<random>';
-- released side effects: broadcast notification.created; INSERT INTO jobs (...)
DELETE FROM cache WHERE `key` = 'resolvedesk-cache-dashboard:admin:stats';
```

**10. Edge cases.**
- Same agent selected again → `false` → flash `info`: "The ticket is already assigned to Sarah Ahmed. Nothing
  was changed." No activity, notification, email, version change or cache clear.
- Agent removed from HRMS a second ago → `canBeAssignedTo()` false → error.
- Page open for a while and someone else changed the ticket → version mismatch → conflict.
- Resolved/Closed ticket → `TicketPolicy::assign()` false → 403.
- Non-admin posting directly → `role:admin` → 403 (`AuthorizationTest::test_non_admin_cannot_assign_by_posting_directly`).

**11. Error handling.**
- `Please select an active support agent.` / `Please select an active support agent who supports this ticket's project.` (key `agent_id`)
- `Only Open, Assigned or In Progress tickets can be assigned.` (key `ticket`, race only)
- `Another assignment is being processed right now. Please try again in a moment.` (lock wait > 5 s)
- `This ticket was changed by someone else after you opened the page. Please review the latest version and try again.` (stale version)

**12. Interview questions.** Why re-read the agent inside the transaction? Why three kinds of lock? Why is the
email queued after commit and after the lock is released? How do you avoid duplicate notifications? Walk me
through the full chain ([07 — Assign Agent chain](07-UI-TO-BACKEND-MAPPING.md#chain-1-assign-agent)).
(Tests: `TicketWorkflowTest`, `TicketScopeTest::test_project_ticket_can_only_be_assigned_to_project_agents`,
`test_global_ticket_can_be_assigned_to_any_active_agent`.)

**13. How to modify.** Allow assigning to admins: change `Ticket::canBeAssignedTo()` and
`TicketAssignmentService::eligibleAgents()`. Disable the assignment email: change the last `true` to `false`.

---

## 6. Automatic assignment

**1. Business requirement.** **Auto Assign** picks the **eligible** agent (section 5) with the lowest workload
(Assigned + In Progress tickets, counted **across all projects**). Ties → lowest user id. No eligible agent → the
ticket stays Open with a clear message and nothing else happens.

**2. Realistic scenario.** TK-000042 is an Open HRMS ticket.

| Agent | id | Supports HRMS? | Active tickets (all projects) |
|---|---:|---|---:|
| Sarah | 2 | yes | 3 (2 HRMS + 1 PAYROLL) |
| David | 3 | yes | 1 (ERP) |
| Michael | 5 | no | 0 |

Admin clicks **Auto Assign** → David is chosen (Michael is not eligible even though he has 0).

**3. Input data.** `POST /tickets/{ticket}/auto-assign` with hidden `version`.

**4. Expected result.** Same database effects as manual assignment with David. Flash:
"Ticket automatically assigned to David Chen (lowest current workload)."

**5. Actual implementation.** `TicketController::autoAssign()` → `TicketAssignmentService::autoAssign()` →
`findLeastBusyAgent($lockedTicket->project_id)` → `performAssignment()` (shared with manual assignment, so all
rules are reused).

**6. Code explanation.**

```php
$chosenAgent = $this->withAssignmentLock(function () use ($ticket, $admin, $expectedVersion) {
    return DB::transaction(function () use ($ticket, $admin, $expectedVersion) {
        $lockedTicket = $this->lockProjectAndTicket($ticket);
        $lockedTicket->ensureVersion($expectedVersion);

        if ($lockedTicket->status !== Ticket::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'ticket' => 'Auto assign is only available for Open tickets. Use Reassign instead.',
            ]);
        }

        // Workload is counted HERE, inside the lock, from current data (never cached).
        $agent = $this->findLeastBusyAgent($lockedTicket->project_id);

        if ($agent === null) {
            return null; // Nothing changes: no activity, no notification, no email.
        }

        $this->performAssignment($lockedTicket, $agent, $admin);

        return $agent;
    }, 3);
});
```

```php
public function findLeastBusyAgent(?int $projectId = null): ?User
{
    return User::where('role', User::ROLE_AGENT)
        ->where('is_active', true)
        ->when($projectId !== null, function ($query) use ($projectId) {
            $query->whereHas('projects', function ($projectQuery) use ($projectId) {
                $projectQuery->where('projects.id', $projectId);
            });
        })
        ->withCount(['assignedTickets as workload_count' => function ($query) {
            $query->whereIn('status', Ticket::WORKLOAD_STATUSES);
        }])
        ->orderBy('workload_count')
        ->orderBy('id')
        ->first();
}
```

- `whereHas('projects', ...)` keeps only agents with a `project_user` row for this project (global ticket: no
  condition).
- `withCount()` adds a sub-query column `workload_count`; no project filter inside → workload across all projects.
- `orderBy('workload_count')` = lowest first; `orderBy('id')` = deterministic tie-breaker; `first()` = `LIMIT 1`.

**7. DB state before.** See scenario; ticket 42 `open`, `assigned_to` NULL, version 1.

**8. DB state after.** ticket 42: `assigned_to = 3`, `status = assigned`, version 2; activities `assigned` +
`status_changed`; one notification for David; one email job.

**9. Equivalent SQL.**

```sql
SELECT users.*,
       (SELECT COUNT(*) FROM tickets
        WHERE users.id = tickets.assigned_to
          AND status IN ('assigned', 'in_progress')) AS workload_count
FROM users
WHERE role = 'agent' AND is_active = 1
  AND EXISTS (SELECT * FROM projects INNER JOIN project_user ON projects.id = project_user.project_id
              WHERE users.id = project_user.user_id AND projects.id = 1)
ORDER BY workload_count ASC, id ASC
LIMIT 1;
```

**10. Edge cases.**
- Tie → lower id wins (`TicketWorkflowTest::test_auto_assign_uses_lowest_user_id_as_tie_breaker`).
- No eligible agent → `null` → flash error, ticket stays Open (`TicketScopeTest::test_auto_assign_with_no_project_agent_leaves_ticket_open`).
- Ticket already Assigned → "only for Open tickets" error (the button is only rendered for Open tickets).
- **V2 fixed the V1 race**: two admins auto-assigning two **different** tickets at the same moment used to be able
  to pick the same agent (the row lock protected only the ticket). The global `Cache::lock('ticket-assignment')`
  now serializes assignments, so the second one counts the first one's result. Verified with 4 parallel PHP
  processes (`DEVELOPMENT_PROGRESS.md`).
- **This is application-level workload distribution, not infrastructure load balancing.** It spreads
  *work among people*, not *HTTP traffic among servers* (that is what Nginx/HAProxy/AWS ELB do).

**11. Error handling.**
- `Auto assign is only available for Open tickets. Use Reassign instead.` (key `ticket`)
- No agent: flash `error` — `No eligible active support agent is available. The ticket remains Open.` (global) or
  `No eligible active support agent for this project is available. The ticket remains Open.` (project ticket)
- Lock timeout and stale version messages as in section 5.

**12. Interview questions.** Explain `withCount()` and `whereHas()`. Why count workload across all projects but
choose only project agents? Why a tie-breaker? Why a cache lock in addition to the row lock? Difference between
workload distribution and load balancing?

**13. How to modify.**
- Count only In Progress: change `Ticket::WORKLOAD_STATUSES` (also changes dropdown counts and dashboards).
- Workload only inside the project: add `->where('project_id', $projectId)` in the `withCount` closure.
- Round-robin instead: store the last assigned agent id and pick the next one.

> **বাংলায়:** Auto assign মানে "যোগ্য agent-দের মধ্যে যার হাতে সবচেয়ে কম কাজ, তাকে দাও"। যোগ্য মানে active আর
> ওই project-এর সদস্য; কিন্তু কাজের সংখ্যা গোনা হয় সব project মিলিয়ে। সমান হলে ছোট id জেতে। Cache lock একসাথে
> দুইটা auto assign চলতে দেয় না, তাই দুজন admin একই মুহূর্তে চাপলেও একই agent-এর ঘাড়ে দুইটা চাপে না।

---

## 7. Reassignment

**1. Business requirement.** Admins can move an **Assigned** or **In Progress** ticket to another **eligible**
agent. Status is kept. Previous and new agent are recorded. V2: the new agent gets in-app + email, and the
**previous agent** gets an in-app "no longer assigned to you" notification. No duplicates if the agent did not
change.

**2. Realistic scenario.** Sarah is on leave. Admin reassigns TK-000041 (HRMS, In Progress, Sarah) to David
(also an HRMS agent).

**3. Input data.** Same form and route as manual assignment: `POST /tickets/{ticket}/assign`, `agent_id`,
`version`. The button label changes to **Reassign** when `$ticket->assigned_to` is set.

**4. Expected result.** `assigned_to` = David, status stays `in_progress`, version + 1, one `reassigned` activity
(old = Sarah, new = David), notification + email for David, in-app notification for Sarah. The ticket disappears
from Sarah's list (her list is `visibleTo()` = created by her OR assigned to her).

**5. Actual implementation.** `TicketAssignmentService::assignAgent()` → `performAssignment()` `else` branch.

**6. Code explanation.**

```php
} else {
    TicketActivity::record($ticket, $admin, 'reassigned', $previousAgent->name, $agent->name);

    $this->notifier->notify($agent, $ticket, $admin, 'reassigned',
        'Ticket reassigned to you',
        "Ticket {$ticket->ticket_number} \"{$ticket->subject}\" has been reassigned to you.",
        true);

    // The previous agent loses access, so the message contains only the ticket number.
    $this->notifier->notify($previousAgent, $ticket, $admin, 'unassigned',
        'Ticket reassigned to another agent',
        "Ticket {$ticket->ticket_number} is no longer assigned to you.",
        false);
}
```

- Status is untouched because the `if ($ticket->status === Ticket::STATUS_OPEN)` block only runs for Open.
- No `status_changed` activity for reassignment (status did not change).
- The previous agent's message has no subject: after reassignment they can no longer open the ticket
  (403), so the notification must not leak its content.

**7. DB state before.**

| tickets.id | assigned_to | status | version |
|---|---|---|---|
| 41 | 2 (Sarah) | in_progress | 3 |

**8. DB state after.**

| tickets.id | assigned_to | status | version |
|---|---|---|---|
| 41 | 3 (David) | in_progress | 4 |

| ticket_activities.action | old_value | new_value |
|---|---|---|
| reassigned | Sarah Ahmed | David Chen |

| notifications.notifiable_id | data.event_type |
|---|---|
| 3 (David) | reassigned |
| 2 (Sarah) | unassigned |

**9. Equivalent SQL.** `UPDATE tickets SET assigned_to = 3, version = 4, updated_at = NOW() WHERE id = 41;` + 1
activity INSERT + 2 notification INSERTs (+ 1 job INSERT for David after commit), inside the same locks as
section 5.

**10. Edge cases.** Same agent → no-op with `info` flash. Resolved/Closed → 403
(`test_resolved_or_closed_tickets_cannot_be_reassigned`). New agent not in the project → `agent_id` error.

**11. Error handling.** Same messages as section 5.

**12. Interview questions.** Why notify the old agent without the subject? Why can't Resolved tickets be
reassigned? (Spec allows reassignment only for Assigned/In Progress; this creates the agent-deactivation and
project-removal limitations in [§1](#1-user-activation-and-deactivation) and §26.)

**13. How to modify.** Email the old agent too: change the last argument of the `unassigned` notification to
`true` (keep the message free of ticket content).

---

## 8. Workload calculation

**1. Business requirement.** An agent's **workload** = number of tickets assigned to them with status Assigned or
In Progress, across all projects. Used by auto-assign, the assign dropdown, and dashboards.

**2. Realistic scenario.** Admin opens the assign dropdown on an HRMS ticket and sees "David Chen (1 active)".

**3. Input data.** None.

**4. Expected result.** Counts match the definition everywhere.

**5. Actual implementation.**
- Constant: `Ticket::WORKLOAD_STATUSES = [STATUS_ASSIGNED, STATUS_IN_PROGRESS]`.
- `TicketAssignmentService::findLeastBusyAgent()` (auto-assign) and `eligibleAgents()` (dropdown).
- `DashboardController::adminDashboard()` → `active_count`, plus `overdue_count` and `resolved_count`
  (Resolved + Closed) per agent (all agents, including inactive).
- `DashboardController::agentDashboard()` → `pending`, `in_progress`, V2 `workload` (= pending + in progress).
- `UserController::show()` → `$assignedActiveCount`.

**6. Code explanation.** One constant drives every count. Resolved tickets are **not** workload (the agent
finished them), but they still block deactivation (§1) and removal from a project (§26).

**7–8. DB state.** Read-only.

**9. Equivalent SQL.**

```sql
SELECT COUNT(*) FROM tickets WHERE assigned_to = 3 AND status IN ('assigned', 'in_progress');
```

The composite index `tickets(assigned_to, status)` makes this fast.

**10. Edge cases.** Overdue tickets count the same as non-overdue. Priority is ignored. Workload is **never
cached**: it is read inside the assignment lock from current data (`V2_MASTER_PROMPT.md` §24).

**11. Error handling.** None (read-only).

**12. Interview questions.** Why a composite index on `(assigned_to, status)`? Why `withCount()` instead of
loading tickets and counting in PHP? Why must workload not be cached?

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

**3. Input data.** `POST /tickets/{ticket}/start` with the hidden V2 `version` field.

**4. Expected result.** Status `in_progress`, activity `status_changed` (Assigned → In Progress), dashboard
cache cleared, flash "Ticket is now In Progress.". **No notification** is sent for Start Working.

**5. Actual implementation.**
- Central table: `Ticket::TRANSITIONS` + `Ticket::canPerform()`.
- Policy: `TicketPolicy::start/resolve/close/reopen()` combine WHO + `canPerform()`.
- Service: `TicketService::startWork()`, `resolve()`, `close()`, `reopen()`, each calling
  `ensureVersion()` (V2) and `ensureActionAllowed()` on the locked row, and `saveNewVersion()` (version + 1).

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
public function startWork(Ticket $ticket, User $user, ?int $expectedVersion = null): void
{
    DB::transaction(function () use ($ticket, $user, $expectedVersion) {
        $lockedTicket = $this->lockTicket($ticket);
        $lockedTicket->ensureVersion($expectedVersion);
        $this->ensureActionAllowed($lockedTicket, 'start');

        $lockedTicket->status = Ticket::STATUS_IN_PROGRESS;
        $this->saveNewVersion($lockedTicket);

        TicketActivity::record($lockedTicket, $user, 'status_changed', 'Assigned', 'In Progress');
    }, 3);

    $this->clearDashboardCache();
}
```

- The status is checked **twice**: in the policy (on the route-bound ticket; gives 403 and hides buttons
  via `@can`) and in `ensureActionAllowed()` (on the locked row; protects against a race where two people
  click at the same time).
- Open → Assigned is not in `TRANSITIONS`; it only happens inside `TicketAssignmentService::performAssignment()`.
- V2: a double-submitted Start (two clicks) → the second request carries the old version → conflict, so only
  one activity is written (`ConsistencyTest::test_every_workflow_mutation_increments_the_version`).
- `TicketController::update()` validates only subject/description/category_id/priority, and `status` is not
  in `$fillable`, so the edit form cannot change status.

**7. DB state before.** ticket 33: `status = assigned`, `assigned_to = 2`.

**8. DB state after.** ticket 33: `status = in_progress`; activity `status_changed` Assigned → In Progress.

**9. Equivalent SQL.**

```sql
START TRANSACTION;
SELECT * FROM tickets WHERE id = 33 LIMIT 1 FOR UPDATE;
UPDATE tickets SET status = 'in_progress', version = 3, updated_at = NOW() WHERE id = 33;
INSERT INTO ticket_activities (...) VALUES (33, 2, 'status_changed', 'Assigned', 'In Progress', 0, ...);
COMMIT;
```

**10. Edge cases.** Open → Closed, Assigned → Resolved, Open → In Progress: all 403
(`TicketWorkflowTest::test_invalid_status_transitions_are_rejected`). Customer trying Resolve → 403. Agent
acting on another agent's ticket → 403.

**11. Error handling.**
- Policy failure → 403 "This action is unauthorized." page.
- Race on the locked row → `This action is not allowed while the ticket is {Status}.` (key `ticket`)
- V2 stale `version` → `This ticket was changed by someone else after you opened the page. Please review the latest version and try again.`

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
public function resolve(Ticket $ticket, string $resolutionNote, User $user, ?int $expectedVersion = null): void
{
    if (trim($resolutionNote) === '') {
        throw ValidationException::withMessages([
            'resolution_note' => 'A resolution note is required to resolve a ticket.',
        ]);
    }

    DB::transaction(function () use ($ticket, $resolutionNote, $user, $expectedVersion) {
        $lockedTicket = $this->lockTicket($ticket);
        $lockedTicket->ensureVersion($expectedVersion);
        $this->ensureActionAllowed($lockedTicket, 'resolve');

        $lockedTicket->status = Ticket::STATUS_RESOLVED;
        $lockedTicket->resolved_at = now();
        $lockedTicket->resolution_note = $resolutionNote;
        $this->saveNewVersion($lockedTicket);

        TicketActivity::record($lockedTicket, $user, 'resolved', 'In Progress', 'Resolved');

        $this->notifier->notify($lockedTicket->creator, $lockedTicket, $user, 'resolved',
            'Ticket resolved',
            "Ticket {$lockedTicket->ticket_number} has been resolved. Please review the resolution note.",
            true);
    }, 3);

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

**10. Edge cases.** V2: the SLA panel now shows "Resolved within SLA" (`resolved_at <= due_at`) or "Resolved after
SLA" (`Ticket::resolvedWithinSla()`). Creator is the resolver (e.g. an agent resolving their own ticket, or an
admin who created it) → `TicketNotifier::notify()` skips self-notification. Double-submitted resolve → one
activity, one notification (`ConsistencyTest::test_double_submitted_resolve_creates_one_activity_and_one_notification`). Inactive creator → skipped. `resolved_at` and `due_at` are
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
assigned agent is still **eligible** (V2: active **and**, for a project ticket, still supports the project). `resolved_at` and the note are cleared; the old note is kept in the activity
history. The agent is notified (in-app + email). **The original SLA deadline is kept.**

**2. Realistic scenario.** John tries again and still cannot log in. He clicks **Reopen Ticket**. The
original deadline (18:00) has already passed (it is now 19:00), so the ticket is **immediately overdue**.

**3. Input data.** `POST /tickets/{ticket}/reopen` (no body).

**4. Expected result.** status `in_progress`, `resolved_at` NULL, `resolution_note` NULL, V2 `sla_notified_at`
NULL and version + 1, activity `reopened` with old value "Previous resolution: …", notification + email to
Sarah, flash
"Ticket reopened and moved back to In Progress.".

**5. Actual implementation.** `TicketController::reopen()` → `TicketService::reopen()`.

**6. Code explanation.**

```php
// The assigned agent must STILL be eligible (active, and still in the project).
$agent = $lockedTicket->assigned_to ? User::find($lockedTicket->assigned_to) : null;
if ($agent === null || ! $lockedTicket->canBeAssignedTo($agent)) {
    throw ValidationException::withMessages([
        'ticket' => 'This ticket cannot be reopened because its assigned agent is no longer active '
            . 'or no longer supports this project. Please contact an administrator.',
    ]);
}

$previousNote = $lockedTicket->resolution_note; // kept in the activity history

$lockedTicket->status = Ticket::STATUS_IN_PROGRESS;
$lockedTicket->resolved_at = null;
$lockedTicket->resolution_note = null;
$lockedTicket->sla_notified_at = null;
$this->saveNewVersion($lockedTicket);

TicketActivity::record($lockedTicket, $user, 'reopened', 'Previous resolution: ' . $previousNote, 'In Progress');
```

- `due_at` is **not** touched. **Deliberate simplified business rule:** the SLA clock is not reset on reopen,
  so a reopened ticket whose deadline has passed shows as overdue at once. This keeps the rule simple and
  honest ("the customer has been waiting since creation").
- V2: `sla_notified_at = null` so the new overdue period produces **one** fresh SLA reminder (section 30)
  (`SlaReportsAndHardeningTest::test_reopening_resets_the_reminder_so_a_new_breach_is_reported`).

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
UPDATE tickets SET status = 'in_progress', resolved_at = NULL, resolution_note = NULL, sla_notified_at = NULL,
       version = version + 1, updated_at = NOW()
WHERE id = 33;   -- PHP sets the new version value; shown as version + 1 for meaning
INSERT INTO ticket_activities (..., action, old_value, new_value)
VALUES (..., 'reopened', 'Previous resolution: Reset your SSO session; please try again.', 'In Progress');
```

**10. Edge cases.** Inactive agent → rejected (normally prevented by §1 rule 3, but a defensive check exists;
`test_reopen_requires_an_active_assigned_agent`). V2: agent removed from the ticket's project → rejected (normally
prevented by §26, because Resolved tickets block the removal). Closed tickets cannot be reopened (403). Reopen by admin
who is also the agent → no self-notification.

**11. Error handling.** `This ticket cannot be reopened because its assigned agent is no longer active or no longer supports this project. Please contact an administrator.` (key `ticket`)

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
$this->saveNewVersion($lockedTicket);

TicketActivity::record($lockedTicket, $user, 'closed', 'Resolved', 'Closed');

// In-app only (no email) for closure.
$this->notifier->notify($lockedTicket->assignedAgent, $lockedTicket, $user, 'closed',
    'Ticket closed',
    "Ticket {$lockedTicket->ticket_number} was confirmed and closed.",
    false);
```

Read-only is enforced by the policy: `update()` returns false when `isClosed()`, `comment()` requires
`! $ticket->isClosed()`, `assign()` requires an active status, and Closed has no entry as a `from` in
`TRANSITIONS`. `addComment()` also re-checks `isClosed()` on the locked row, V2 `addInternalNote()` requires
`comment()`, and the optimistic UPDATE in `updateTicket()` has `status <> 'closed'`.

**7–8. DB state.** `status`: resolved → closed; `resolved_at` unchanged.

**9. Equivalent SQL.** `UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id = 33;`

**10. Edge cases.** Agent cannot close (not creator) unless they created the ticket. Closing an Open ticket →
403. Comment on a closed ticket → 403 (`test_closed_tickets_are_read_only`).

**11. Error handling.** Policy 403; race → `This action is not allowed while the ticket is Closed.`

**12. Interview questions.** Why no email on close? How is "read-only" enforced — UI or backend? (Both;
backend is the real one.)

**13. How to modify.** Auto-close after X days: a second scheduled command, built like `tickets:check-sla` (§30).
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

**4. Expected result.** `due_at` = 12:00; V2 `sla_notified_at` reset to NULL and version + 1; activities
`priority_changed` (Medium → Critical) and `due_date_changed` (old → new, formatted `d M Y, h:i A`).

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
// TicketService::updateTicket(Ticket $ticket, array $data, int $expectedVersion, User $editor)
$ticket->fill($data);
if (! $ticket->isDirty()) {
    return false;                            // nothing changed -> no activity, "No changes were made."
}
$oldPriority = $ticket->getOriginal('priority');
$oldDueAt = $ticket->due_at;
$priorityChanged = $ticket->isDirty('priority');
...
if ($priorityChanged) {
    // SLA is always recalculated from the ORIGINAL creation time, and a new
    // deadline gets a fresh SLA-breach reminder (sla_notified_at reset).
    $ticket->due_at = Ticket::calculateDueAt($ticket->created_at, $ticket->priority);
    $ticket->sla_notified_at = null;
}

DB::transaction(function () use (...) {
    $changes = $ticket->getDirty();
    $changes['version'] = DB::raw('version + 1');
    $changes['updated_at'] = now();

    $updatedRows = Ticket::whereKey($ticket->id)
        ->where('version', $expectedVersion)
        ->where('status', '!=', Ticket::STATUS_CLOSED)
        ->update($changes);

    if ($updatedRows === 0) {
        throw TicketConflictException::staleVersion();
    }
    ...
    if ($priorityChanged) {
        TicketActivity::record($ticket, $editor, 'priority_changed', Ticket::PRIORITIES[$oldPriority], $ticket->priorityLabel());
        TicketActivity::record($ticket, $editor, 'due_date_changed', $this->formatDate($oldDueAt), $this->formatDate($ticket->due_at));
    }
}, 3);
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
UPDATE tickets SET priority = 'critical', due_at = DATE_ADD(created_at, INTERVAL 2 HOUR), sla_notified_at = NULL,
       version = version + 1, updated_at = NOW()
WHERE id = 40 AND version = 1 AND status <> 'closed';   -- PHP computes due_at; this SQL shows the meaning
```

**10. Edge cases.** Lowering priority on an overdue ticket can make it not overdue. Raising priority can make
it overdue instantly. No changes → "No changes were made." and no activity
(`test_editing_without_changes_records_no_activity`). Reopen does **not** recalculate (see §11).

**11. Error handling.** Invalid priority → `The selected priority is invalid.` (standard `in` rule).

**12. Interview questions.** Why recalculate from `created_at` and not from now? Why `copy()`? Why DATETIME
for `due_at`? How would you support business hours? (Out of scope; would need a calendar.)

**13. How to modify.** Change hours in `Ticket::SLA_HOURS`. Existing tickets keep their stored `due_at`; a
data migration would be needed to update them. V2: `updateTicket()` uses optimistic locking (version check in the
UPDATE) instead of a row lock — see §31.

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

**1. Business requirement.** Anyone who can view a non-closed ticket can post a **public reply**. Staff can also
post an **internal note** (section 28). Participants other than the author are notified in-app; no duplicates,
no self-notification, inactive users skipped. V2: a **customer's public reply also emails the assigned agent**;
all other comments are in-app only. Comments now also write an activity row.

**2. Realistic scenario.** John comments "Still failing on Chrome" → Sarah (assigned agent) gets an in-app
notification **and an email**. Sarah replies → John is notified in-app only. Admin replies → both, in-app only.

**3. Input data.** `POST /tickets/{ticket}/comments`: `message` (`required`, `string`, `max:5000`), V2
`visibility` (`nullable`, `public|internal`), V2 `attachments[]`. Route middleware `throttle:comments` (30/min).

**4. Expected result.** Row in `ticket_comments` (`visibility = public`), activity `comment_added`,
notifications for the other participants, email job for the agent when the author is the customer, redirect to
`tickets.show#conversation`, flash "Comment added.".

**5. Actual implementation.** `TicketCommentController::store()` → `Gate::authorize('comment', $ticket)` (+
`addInternalNote` for internal) → `TicketService::addComment()` → private `notifyCommentRecipients()` →
`TicketNotifier::notify()`.

**6. Code explanation.**

```php
// TicketService::notifyCommentRecipients() - public reply branch
$recipients = [$creator, $agent];
...
$alreadyNotifiedIds = [];
foreach ($recipients as $recipient) {
    if ($recipient === null || in_array($recipient->id, $alreadyNotifiedIds, true)) {
        continue; // no agent yet, or creator and agent are the same person
    }
    $alreadyNotifiedIds[] = $recipient->id;

    // Email only for a customer's public reply to the assigned agent.
    $sendEmail = ! $isInternal
        && $author->isCustomer()
        && $agent !== null
        && $recipient->id === $agent->id;

    $this->notifier->notify($recipient, $ticket, $author, $isInternal ? 'internal_note' : 'comment', $title, $text, $sendEmail);
}
```

**The recipient rule (public reply):** recipients = {creator, assigned agent} − {author} − {inactive} −
{duplicates}. Email only when author = customer and recipient = assigned agent.

| Author | Creator gets | Agent gets |
|---|---|---|
| Creator (customer) | — (self) | in-app **+ email** |
| Assigned agent | in-app | — (self) |
| Admin (not creator/agent) | in-app | in-app |
| Creator who is also the agent | — | — |
| Creator on an unassigned ticket | — | (no agent) → nobody |

- Why email the agent for customer replies? A waiting customer is the most time-critical message; agents
  replying to customers stay in-app to avoid email noise (and the customer sees it on the page and in the bell).
- `mb_strimwidth($message, 0, 80, '...')` shortens the preview safely for Bangla text.
- V2: `TicketActivity::record(..., 'comment_added', ...)` writes a timeline row; files add `attachment_added`.
- `message`, `visibility`, `ticket_id`, `user_id` are set explicitly (`TicketComment::$fillable` only has `message`).
- Comments do not clear the dashboard cache (comments are not in the statistics).

**7. DB state before.** No comments on ticket 41; Sarah has 0 unread.

**8. DB state after.**

| ticket_comments.ticket_id | user_id | visibility | message |
|---|---|---|---|
| 41 | 6 (John) | public | Still failing on Chrome |

| ticket_activities.action | user_id | is_internal |
|---|---|---|
| comment_added | 6 | 0 |

| notifications.notifiable_id | data.event_type | data.title |
|---|---|---|
| 2 (Sarah) | comment | New comment from John Carter |

`jobs`: one `SendTicketEmail` for Sarah (after commit).

**9. Equivalent SQL.**

```sql
START TRANSACTION;
SELECT * FROM tickets WHERE id = 41 LIMIT 1 FOR UPDATE;
INSERT INTO ticket_comments (message, visibility, ticket_id, user_id, created_at, updated_at)
VALUES ('Still failing on Chrome', 'public', 41, 6, ...);
INSERT INTO ticket_activities (ticket_id, user_id, action, old_value, new_value, is_internal, ...)
VALUES (41, 6, 'comment_added', NULL, NULL, 0, ...);
SELECT * FROM users WHERE id = 6;  SELECT * FROM users WHERE id = 2;      -- creator, assignedAgent
INSERT INTO notifications (...) VALUES (..., 2, '{"event_type":"comment",...}', ...);
COMMIT;
-- after commit: broadcast to users.2; INSERT INTO jobs (SendTicketEmail for Sarah)
```

**10. Edge cases.** Closed ticket → 403 (policy) or `Closed tickets are read-only. Comments are not allowed.`
(race). Admin is the creator → only the agent is notified, no email (author is not a customer). Tests:
`NotificationTest::test_creator_comment_notifies_only_the_assigned_agent`, `test_agent_reply_notifies_the_creator`,
`test_admin_comment_notifies_creator_and_agent`, `test_no_duplicate_when_creator_is_also_the_assigned_agent`,
`test_inactive_recipient_is_skipped` (V2: `test_creator_comment_notifies_only_the_assigned_agent` also asserts exactly one `SendTicketEmail` job to the agent).

**11. Error handling.** `The message field is required.`; `Closed tickets are read-only. Comments are not allowed.`
(key `message`); file validation messages; 429 after 30 comments per minute.

**12. Interview questions.** How do you avoid duplicate notifications? Why does only a customer reply send an
email? How are internal notes kept away from customers (section 28)?

**13. How to modify.** Email for every public reply: change the `$sendEmail` condition to `! $isInternal`.
Notify all admins too: append `User::where('role', 'admin')->where('is_active', true)->get()` to `$recipients`.

---

## 16. Activity logging

**1. Business requirement.** Every important ticket change is recorded (who, what, old value, new value,
when) and shown as a timeline on the ticket page. V2: system actions are distinguishable (`user_id = NULL`), and
staff-only entries (`is_internal = 1`) are hidden from customers.

**2. Realistic scenario.** An auditor asks "who changed the priority of TK-000040 and when?" → the timeline
shows "Nadia Rahman (admin) changed the priority Medium → Critical".

**3. Input data.** Written by the service, never by the user.

**4. Expected result.** One row per change; never for no-op operations.

**5. Actual implementation.** V2 static `TicketActivity::record()` (replaced the private
`TicketService::recordActivity()`); labels in `TicketActivity::ACTION_LABELS`; `scopeVisibleTo()` and
`isSystem()`; displayed in `resources/views/tickets/show.blade.php` (Activity History card).

**6. Code explanation.**

```php
public static function record(Ticket $ticket, ?User $user, string $action, ?string $oldValue, ?string $newValue, bool $isInternal = false): self
{
    return self::create([
        'ticket_id' => $ticket->id,
        'user_id' => $user?->id,
        'action' => $action,
        'old_value' => $oldValue,
        'new_value' => $newValue,
        'is_internal' => $isInternal,
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
| `assigned` | `TicketAssignmentService::performAssignment()` (first assignment) | NULL → agent name |
| `status_changed` | `performAssignment()` (Open → Assigned) | `Open` → `Assigned` |
| `status_changed` | `startWork()` | `Assigned` → `In Progress` |
| `reassigned` | `performAssignment()` (agent already set) | old agent name → new agent name |
| `resolved` | `resolve()` | `In Progress` → `Resolved` |
| `reopened` | `reopen()` | `Previous resolution: <note>` → `In Progress` |
| `closed` | `close()` | `Resolved` → `Closed` |
| `comment_added` (V2) | `addComment()` (public) | NULL → NULL |
| `internal_note_added` (V2) | `addComment()` (internal, `is_internal = 1`) | NULL → NULL |
| `attachment_added` (V2) | `createTicket()` / `addComment()` with files (internal if the note is internal) | NULL → file names |
| `sla_breached` (V2) | `processSlaBreach()`, **`user_id = NULL`** | NULL → `Deadline was 26 Nov 2026, 11:00 AM` |

Not logged: user changes, category changes, project/client changes, notifications read.

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

**13. How to modify.** New action: call `TicketActivity::record()` with a new name and add a label to
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

**V2 additions.** Admin: active projects/clients/agents, global vs project tickets, tickets by project and by
client (charts), SLA compliance % (all cached in `dashboard:admin:stats`), recent activity (live). Agent: workload
(Assigned + In Progress) and recent activity on tickets they may see. Customer: overdue count and their 5 latest
notifications. Details: [22 — section 12](22-V2-BUSINESS-FEATURES.md#12-dashboards).

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

**5. Actual implementation.** `DB::transaction(..., 3)` (3 attempts on deadlock) in every write method of
`TicketService` (`createTicket`, `updateTicket`, `startWork`, `resolve`, `reopen`, `close`, `addComment`,
`processSlaBreach`), `TicketAssignmentService` (`assignAgent`, `autoAssign`) and `ProjectService`
(`syncAgents`, `syncClients`). Ticket row lock (`lockTicket()` / `lockProjectAndTicket()`) in all ticket mutations
except `createTicket` (new row) and `updateTicket` (optimistic locking instead). V2 also locks the **project** row
(shared for creation/assignment, exclusive for association changes) and uses `Cache::lock('ticket-assignment')`
around assignments.

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
- V2: with the assignment cache lock the second request in the scenario waits for the lock first; it still
  performs a reassignment if it carries no `version`, but a normal form submit carries the version it showed
  (1), which no longer matches (2) → conflict message instead of a silent reassignment.
- V2: two auto-assigns of different tickets are serialized by the cache lock (§6).
- V2: `updateTicket()` uses **optimistic** locking (`WHERE version = ?`) — no lock is held while the user edits.
- `createTicket()` does not need a ticket lock (new row); the UNIQUE `(created_by, request_token)` index protects
  against duplicates (§31).
- Lock order everywhere: assignment cache lock → project row → ticket row → agent's users row (prevents deadlocks).
  `UserController::update()/toggleActive()` lock only the target users row first, then read (no cycle).
- After locking, `TicketService` re-runs the TicketPolicy check on the LOCKED row (`authorizeOnCurrentState()`),
  because the route-bound copy the controller authorized may be stale (e.g. reassigned meanwhile).
- A lock wait that takes too long raises a lock-wait-timeout `QueryException` (InnoDB default 50 s).
- Test: `QueueAndEmailTest::test_no_email_job_is_dispatched_when_the_transaction_rolls_back` proves that a
  rollback removes the ticket change, the activities and the notifications, and that no email job is pushed.

**11. Error handling.** Any exception inside the closure → rollback → exception continues to Laravel's
handler (validation → redirect back; other → 500).

**12. Interview questions.** What is a transaction? What does `FOR UPDATE` do? Why must the lock be inside a
transaction? What is "lost update"? Why are emails not sent inside the transaction? Optimistic vs pessimistic
locking?

**13. How to modify.** Make the conflict check mandatory for workflow buttons: change the `version` rule from
`nullable` to `required` in `TicketController`. Details on all locks: concurrency section of
[22 — section 4.2](22-V2-BUSINESS-FEATURES.md#42-locking-and-the-concurrency-limitation) and §8.3.

> **বাংলায়:** Transaction মানে "সব নয়তো কিছুই না" — ticket update, activity, notification তিনটাই হবে, অথবা কোনোটাই
> হবে না (rollback)। Row locking (`FOR UPDATE`) মানে একজন যখন ticket-টা নিয়ে কাজ করছে, অন্যজন একই row lock করতে
> চাইলে অপেক্ষা করবে। Lock শুধু transaction শেষ (commit/rollback) হলে ছাড়ে, তাই lock সবসময় transaction-এর ভিতরে
> দিতে হয়।

---

## 19. Notifications (matrix as implemented)

**1. Business requirement.** Relevant people get a persistent in-app notification (and some get an email) for
ticket events, delivered in real time when possible, without refreshing the page. A failed real-time delivery
must never lose the notification or fail the ticket operation.

**2. Realistic scenario.** Admin assigns a ticket; within about a second Sarah's bell badge increases and a toast
appears (WebSocket). If Reverb is stopped, the same happens within ~5 seconds through polling.

**3. Input data.** Triggered by ticket operations and the SLA scheduler.

**4. Expected result — matrix as implemented (V2):**

| Event (`event_type`) | Method | Recipient | In-app | Queued email |
|---|---|---|---|---|
| `created` | `TicketService::createTicket()` | Every active admin, except the creator | Yes | Yes |
| `assigned` | `TicketAssignmentService::performAssignment()` | Assigned agent | Yes | Yes |
| `reassigned` | `performAssignment()` | New agent | Yes | Yes |
| `unassigned` (V2) | `performAssignment()` | Previous agent | Yes | No |
| `comment` | `TicketService::addComment()` | Creator and/or assigned agent, except author | Yes | Only customer reply → agent (V2) |
| `internal_note` (V2) | `addComment()` | Assigned agent + creator if staff, except author | Yes | No |
| `resolved` | `resolve()` | Ticket creator | Yes | Yes |
| `reopened` | `reopen()` | Assigned agent | Yes | Yes |
| `closed` | `close()` | Assigned agent | Yes | No |
| `sla_breached` (V2) | `processSlaBreach()` | Assigned active agent, else every active admin | Yes | Yes |
| (none) | `updateTicket()`, `startWork()` | — | No | No |

The `created` row was added at the project owner's request, beyond the original MASTER_PROMPT §9.2 matrix.

Common filter in `TicketNotifier::notify()`: skip if the recipient is `null`, **inactive**, or **the actor**
(no self-notification; `$actor = null` = system, nobody skipped). No notification when nothing changed.

**5. Actual implementation.** V2 `app/Services/TicketNotifier.php` (`notify()`, `notifyAdmins()`, private
`queueEmail()`, `broadcast()`, `afterCommit()`, `holdSideEffects()` / `releaseSideEffects()`) →
`$recipient->notify(new TicketNotification(...))` (channel `database`, **not queued**) → after commit
`event(new UserNotificationCreated($recipientId, $notificationId))` (`ShouldBroadcastNow`, private channel
`users.{id}`, payload only the id). Reading: `NotificationController` + `public/js/notifications.js` +
`resources/js/realtime.js`.

**6. Code explanation.**

```php
public function notify(?User $recipient, Ticket $ticket, ?User $actor, string $eventType, string $title, string $message, bool $sendEmail): void
{
    if ($recipient === null || ! $recipient->is_active) {
        return;
    }

    if ($actor !== null && $recipient->id === $actor->id) {
        return; // Do not notify people about their own actions.
    }

    $notification = new TicketNotification($ticket, $eventType, $title, $message);
    $notification->id = (string) Str::uuid();
    $recipient->notify($notification);

    $recipientId = $recipient->id;
    $notificationId = $notification->id;
    $this->afterCommit(function () use ($recipientId, $notificationId) {
        $this->broadcast($recipientId, $notificationId);
    });

    if ($sendEmail) {
        $this->queueEmail($recipient, $ticket, $title, $message, $notificationId);
    }
}
```

- The database row is the source of truth (inside the transaction). The broadcast is only a "something new"
  signal; the browser then calls the authorized feed, so no ticket content or internal note travels over the
  WebSocket.
- `broadcast()` wraps `event(...)` in try/catch: Reverb down → `Log::warning(...)`, the operation still succeeds,
  polling delivers it (`RealtimeAndDeliveryTest::test_failed_broadcast_does_not_break_the_operation`).
- `routes/channels.php` allows `users.{userId}` only for the same, active user.

**7. DB state before.** `notifications` empty for user 2.

**8. DB state after.**

| id (UUID) | notifiable_type | notifiable_id | data | read_at |
|---|---|---|---|---|
| 9b1e… | App\Models\User | 2 | `{"ticket_id":41,"ticket_number":"TK-000041","title":"New ticket assigned","message":"Ticket TK-000041 \"Leave balance shows negative days\" has been assigned to you.","event_type":"assigned"}` | NULL |

**9. Equivalent SQL.** Insert above; reading:
`SELECT * FROM notifications WHERE notifiable_type = 'App\\Models\\User' AND notifiable_id = 2 ORDER BY created_at DESC LIMIT 5;`
(V2 index `(notifiable_type, notifiable_id, created_at)` removes the filesort.)

**10. Edge cases.** Inactive recipient → no notification. Several open tabs each receive the event (each shows
the toast). Build missing (`public/build/manifest.json`) → no Echo, polling only. Details:
[Notification system guide](13-NOTIFICATION-SYSTEM-GUIDE.md).

**11. Error handling.** A failed notification insert rolls back the whole operation. Broadcast/email dispatch
failures are logged only. Someone else's notification id → 404 (`notifications()->findOrFail($id)`).
Subscribing to another user's channel → 403 from `/broadcasting/auth`.

**12. Interview questions.** Why store first and broadcast after commit? Why send only the id over the WebSocket?
Why `ShouldBroadcastNow` instead of a queued broadcast? What happens when Reverb is down?

**13. How to modify.** New event: call `$this->notifier->notify()` with a new `event_type`, then update this
matrix. Change recipients per event in the corresponding service method.

> **বাংলায়:** Notification আগে database-এ লেখা হয় (transaction-এর ভেতরে) — এটাই আসল রেকর্ড। Commit-এর পরে
> WebSocket দিয়ে শুধু id পাঠানো হয়; browser তারপর নিজের authorization সহ feed থেকে আসল তথ্য আনে। Reverb বন্ধ
> থাকলে কিছু হারায় না — polling পরে নিয়ে আসে।

---

## 20. Email job dispatch after commit

**1. Business requirement.** Emails are sent by a queued job, **only after the database commit**, and an
email problem must never undo or hide a committed ticket change.

**2. Realistic scenario.** Admin assigns a ticket while the queue worker is stopped. The assignment succeeds
immediately; the email waits in the `jobs` table until `php artisan queue:work` runs.

**3. Input data.** Recipient email/name + small array of ticket data.

**4. Expected result.** On commit → one `jobs` row. On rollback → no job. If pushing the job fails → error
logged, user still sees success.

**5. Actual implementation.** V2 `TicketNotifier::notify()` → private `queueEmail()` → `afterCommit()` (wraps
`DB::afterCommit()`, held while an assignment lock is held) → `SendTicketEmail::dispatch()`
(`app/Jobs/SendTicketEmail.php`: `$tries = 3`, `$backoff = 10`, V2 `$timeout = 60`, `delivery_key` de-duplication,
`failed()`)
→ `app/Mail/TicketUpdateMail.php` → `resources/views/emails/ticket-update.blade.php`.

**6. Code explanation.**

```php
$this->afterCommit(function () use ($recipientEmail, $recipientName, $emailData) {
    try {
        SendTicketEmail::dispatch($recipientEmail, $recipientName, $emailData);
    } catch (Throwable $exception) {
        // The ticket change is already committed: log, do not fail the request.
        Log::error('Could not queue ticket email', [
            'ticket_id' => $emailData['ticket_id'],
            'error' => $exception->getMessage(),
        ]);
    }
});
```

- `DB::afterCommit()` holds the closure until the **outermost** transaction commits. On rollback, the closure
  is discarded.
- Only plain values are passed (not the model), so the job payload is small and stable. V2 adds `project`
  (shown in the email) and `delivery_key` (= notification id): after a successful send the job stores
  `ticket-email-sent:{key}` in the cache for 2 days, so a retried job does not send twice
  (`RealtimeAndDeliveryTest::test_retried_email_job_does_not_send_twice`).
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
| `dashboard:admin:stats` (`TicketService::DASHBOARD_CACHE_KEY`) | `DashboardController::adminDashboard()` | 60 s | `TicketService::clearDashboardCache()` after create, update (if changed), start, resolve, reopen, close, V2 SLA reminder; V2 `TicketAssignmentService` after assign/auto-assign (if changed); `CategoryController::clearCaches()`; V2 `ProjectController`/`ClientController::clearCaches()`, `ProjectService` (associations), `UserController` (store, update, toggle) |
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

**10. Edge cases.** V2: user changes **do** clear the dashboard cache now, because the cached stats contain the
"Active agents" count. Agent eligibility, workload and permissions are never cached (`V2_MASTER_PROMPT.md` §24). Tests: `CacheTest.php` (all eight tests).

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
| 3. `role:admin` middleware | Role for admin-only routes (assign, auto-assign, users, categories, V2 projects, clients, reports/export) | `RoleMiddleware` |
| 4. Policy | This user + this ticket + current status | `TicketPolicy` via `Gate::authorize()` in controllers, `@can` in Blade |
| + | Own data only | `Ticket::scopeVisibleTo()` for lists; `$request->user()->notifications()` for notifications |
| + | CSRF | `web` middleware group; every form has `@csrf`; JS sends `X-CSRF-TOKEN` |
| + V2 | Scope ownership | `TicketService::resolveScope()` (customer's own client, agent's projects, active records) |
| + V2 | Private channel | `routes/channels.php` (`users.{id}` only for that active user) |
| + V2 | Rate limits | `throttle:ticket-create`, `comments`, `notifications`, `exports` |

**5. Actual implementation.** `routes/web.php`, `app/Http/Middleware/*`, `app/Policies/TicketPolicy.php`,
`TicketController`, `TicketCommentController`.

**6. Code explanation.**

| Policy method | Rule |
|---|---|
| `view` | admin, or creator, or assigned agent |
| `create` | everyone (active), except a customer whose client is inactive (V2) |
| `update` | not closed AND (admin OR (creator AND status open AND unassigned)) |
| `assign` | admin AND status in open/assigned/in_progress |
| `start` | (admin OR assigned agent) AND `canPerform('start')` |
| `resolve` | (admin OR assigned agent) AND `canPerform('resolve')` |
| `close` | (admin OR creator) AND `canPerform('close')` |
| `reopen` | (admin OR creator) AND `canPerform('reopen')` |
| `comment` | `view` AND not closed |
| `addInternalNote` (V2) | staff (admin/agent) AND `comment` |
| `downloadAttachment` (V2) | attachment belongs to the ticket AND `view`; internal-note files staff only |

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
to a ticket you lost access to (e.g. reassigned) → 403 page with a hint. User, category, V2 project, client and
report routes have no policy — `role:admin` is enough there. V2: project membership does **not** grant ticket
access (`TicketScopeTest::test_project_membership_does_not_grant_access_to_project_tickets`).

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

1. **Resolved tickets cannot be reassigned**, so an agent with Resolved tickets can only be deactivated (or
   removed from that project) after those tickets are **closed**.
2. **Reopen keeps the original SLA**; a reopened ticket can be overdue immediately (it then gets one fresh SLA
   reminder because `sla_notified_at` is reset).
3. **Average resolution time** = `created_at` → latest `resolved_at`; reopen periods are not separated.
4. **Workload distribution is application-level** (people), not infrastructure load balancing (servers).
5. **The assignment lock is global** (`Cache::lock('ticket-assignment')`, 10 s): one assignment at a time across
   the whole system. It coordinates workload; the ticket row lock + status/version checks remain the real
   guarantee.
6. SLA uses continuous calendar hours (no business hours/holidays). Timestamps are stored in `Asia/Dhaka`, not UTC
   (V1 data was stored that way).
7. **SLA reminders increase `version`**, so an edit form opened before a reminder gets a conflict and must reload.
8. A customer's organization cannot change once they created client tickets (create a new account).
9. Project/client associations are edited only on the project page.
10. Email de-duplication uses the cache (2 days); a crash between "SMTP accepted" and "cache saved" can still send
    one duplicate (queues are at-least-once).
11. No antivirus scanning and no deletion UI for attachments.
12. The ticket list's **project filter** lists every project to every role (results are still limited by
    `visibleTo()`).

Fixed in V2 (were limitations in V1): simultaneous auto-assign of different tickets (now serialized by the cache
lock), `updateTicket()` lost updates (now optimistic locking), previous agent not notified on reassignment, no
real-time push (now Reverb + polling fallback), no SLA escalation (now `tickets:check-sla`, section 30).

### Future improvements (NOT implemented)

- Escalation levels (e.g. notify admins if an assigned ticket is still overdue after X hours) — would need a
  second timestamp such as `escalated_at` and another branch in the SLA command.
- Auto-close of Resolved tickets after N days — a second scheduled command.
- Redis for cache, queue and Reverb scaling; `onOneServer()` for the scheduler on several servers.
- An outbox table for guaranteed email delivery; business-hours SLA calendars; a customer-manager role that sees
  all tickets of the client (`scopeVisibleTo()` branch on `client_id`).

> **বাংলায়:** V1-এর কয়েকটা সীমাবদ্ধতা V2-তে ঠিক করা হয়েছে (auto assign race, lost update, real-time, SLA
> reminder)। যেগুলো রয়ে গেছে সেগুলো জেনেশুনে রাখা — interview-এ সেগুলো "known limitation + কীভাবে ঠিক করা যায়"
> হিসেবে বলবে।

---

## 24. Projects and client organizations (V2)

**1. Business requirement.** Admins manage projects (products) and clients (customer companies): create, edit,
search, paginate, activate/deactivate. Codes are unique. Never deleted while referenced. Inactive = not selectable
for new tickets; existing tickets stay viewable and resolvable.

**2. Realistic scenario.** The company discontinues "Inventory Management System" (INV). The admin edits INV and
switches **Active** off. Old INV tickets still open and can be resolved; nobody can create a new INV ticket.

**3. Input data.** `PUT /projects/{project}`: `code` (required, max 30, `alpha_dash`, unique ignoring self),
`name` (max 150), `description` (max 2000), `is_active` (checkbox).

**4. Expected result.** `projects.is_active = 0`; INV disappears from `selectableProjects()` and the
`project-options` endpoint; `resolveScope()` rejects it; dashboard "Active projects" decreases.

**5. Actual implementation.** `ProjectController` / `ClientController` (`validateProject()` / `validateClient()`,
`clearCaches()`), `Project::scopeActive()`, `TicketController::selectableProjects()`, `TicketService::resolveScope()`,
`TicketPolicy::create()` (inactive client).

**6. Code explanation.** No `destroy` route: `Route::resource('projects', ProjectController::class)->except(['destroy'])`.
Deactivation has no extra check because it does not break any existing ticket. The FKs (`tickets.project_id`,
pivots) are `restrictOnDelete()`, so even a manual DELETE would be refused while referenced.

**7. DB state before.** `projects`: (5, INV, is_active 1).

**8. DB state after.** `projects`: (5, INV, is_active 0).

**9. Equivalent SQL.** `UPDATE projects SET is_active = 0, updated_at = NOW() WHERE id = 5;` then
`DELETE FROM cache WHERE key = '...dashboard:admin:stats';`

**10. Edge cases.** An inactive project that is already linked stays checked (with an "Inactive" badge) on the
project page. An inactive client's customers get 403 on Create Ticket. Duplicate code → validation error.

**11. Error handling.** `The code has already been taken.`; creating a ticket for INV → `Please choose an active project.`

**12. Interview questions.** Why deactivate instead of delete? What happens to existing tickets? Where is
"active" checked — UI or backend? (Both; backend in `resolveScope()`.)

**13. How to modify.** Block deactivation while open tickets exist: count `Ticket::where('project_id', ...)->whereIn('status', Ticket::ACTIVE_STATUSES)`
in `ProjectController::update()` and throw a `ValidationException`.

---

## 25. Customer to client mapping (V2)

**1. Business requirement.** Only customers may belong to a client (`users.client_id`). A new link needs an active
client. Standalone customers (NULL) create global tickets only. A customer with client tickets cannot change
organization. Ownership is never transferred silently.

**2. Realistic scenario.** HR asks to move John Carter from ACME to GLOBEX. John already created 2 ACME tickets.

**3. Input data.** `PUT /users/6` with `client_id=2` (and the other user fields).

**4. Expected result.** Refused: "This customer already created 2 ticket(s) for their organization, so the
organization (and the customer role) cannot be changed. Create a new user account instead."

**5. Actual implementation.** `UserController::update()` → `ensureClientChangeIsAllowed()`; `store()` uses
`Rule::exists('clients', 'id')->where('is_active', true)`; `client_id` is not in `User::$fillable`.

**6. Code explanation.** See [22 — section 3](22-V2-BUSINESS-FEATURES.md#3-customer-to-client-mapping-rules).
The guard counts `Ticket::where('created_by', $user->id)->whereNotNull('client_id')`. Changing the role from
customer to agent sets `$newClientId = null`, which is also a client change and is therefore blocked too.

**7. DB state before.** `users`: (6, John, customer, client_id 1); 2 tickets with `created_by = 6`, `client_id = 1`.

**8. DB state after.** Unchanged.

**9. Equivalent SQL.** `SELECT COUNT(*) AS aggregate FROM tickets WHERE created_by = 6 AND client_id IS NOT NULL;`

**10. Edge cases.** John has only global tickets → change allowed. Keeping a now-inactive client → allowed.
Selecting a client for an agent → ignored (stored as NULL) (`ProjectAndClientTest::test_agents_never_get_a_client`).

**11. Error handling.** `Please choose an active client organization.`; the "already created" message above
(key `client_id`).

**12. Interview questions.** Why not just update `client_id`? What would leak? How would you support moving
employees between companies? (New account, or a data migration that also re-scopes tickets — out of scope.)

**13. How to modify.** Allow the move when all client tickets are Closed: add `->where('status', '!=', Ticket::STATUS_CLOSED)`
to the count (they would still be visible to John as their creator — explain the trade-off).

---

## 26. Project associations and removal guards (V2)

**1. Business requirement.** Admins link clients and agents to a project. Only active ones can be newly added.
A link cannot be removed while unresolved or reopenable tickets depend on it. Changes are transactional and
protected against concurrent ticket creation/assignment.

**2. Realistic scenario.** Admin unchecks Sarah on HRMS while Sarah still has TK-000041 In Progress.

**3. Input data.** `PUT /projects/1/agents` with `agent_ids[] = [3]` (complete wanted list).

**4. Expected result.** Refused, nothing changes: "Sarah Ahmed still has 1 unresolved or reopenable ticket(s) in
this project. Reassign or close them before removing the agent."

**5. Actual implementation.** `ProjectController::updateAgents()` / `updateClients()` →
`ProjectService::syncAgents()` / `syncClients()`.

**6. Code explanation.** Lock project row `FOR UPDATE` → `array_diff` for added/removed → active check for added →
dependent-ticket count (`Ticket::OPEN_OR_REOPENABLE_STATUSES`) per removed id → `sync()`. Ticket creation and
assignment take a **shared** lock on the same project row, so the check cannot race with them. Full code:
[22 — section 4](22-V2-BUSINESS-FEATURES.md#4-project-associations-clients-and-agents).

**7. DB state before.** `project_user`: (1, 2), (1, 3); TK-000041 (project 1, assigned 2, in_progress).

**8. DB state after.** Unchanged (rollback).

**9. Equivalent SQL.** See [22 — 15.3](22-V2-BUSINESS-FEATURES.md#153-remove-an-agent-from-a-project-blocked-case).

**10. Edge cases.** Sarah's only HRMS ticket is Resolved → still blocked (it can be reopened). Closed tickets →
not blocking. Removing a client works the same with `client_id` instead of `assigned_to`. Adding an inactive agent
→ "Only active support agents can be added to a project."

**11. Error handling.** Messages above, shown under the checklist (`@error('agent_ids')` / `@error('client_ids')`).

**12. Interview questions.** Why lock the project row? Why shared lock for creation but exclusive for removal?
Why are Resolved tickets "reopenable" dependencies? What does `sync()` do in SQL?
(Tests: `ProjectAndClientTest::test_agent_cannot_be_removed_while_responsible_for_project_tickets`,
`test_client_cannot_be_removed_while_it_has_open_tickets_in_the_project`,
`test_client_can_be_removed_when_its_project_tickets_are_closed`.)

**13. How to modify.** Allow removal when only Resolved tickets remain: use `Ticket::ACTIVE_STATUSES` in the guard —
but then reopening those tickets would fail eligibility (`reopen()`), so you would need a reassignment flow.

---

## 27. Ticket scope and role-specific creation (V2)

**1. Business requirement.** A ticket may be project + client, project only (internal), client only (global),
or neither (global internal). Global ≠ public. Customers' client is derived from their account; agents create
internal tickets for projects they support; admins may choose any active combination where the client uses the
project. Scope is immutable after creation.

**2. Realistic scenario.** Agent David (ERP, HRMS) tries to create an internal PAYROLL ticket.

**3. Input data.** `POST /tickets` with `project_id = 3` (PAYROLL), `client_id = 1` (ignored for agents).

**4. Expected result.** Refused: "You can only create tickets for projects you support."

**5. Actual implementation.** `TicketService::resolveScope()` (inside the creation transaction, project row
shared lock); dropdown helper `TicketController::selectableProjects()` and the AJAX endpoint
`TicketController::projectOptions()` (convenience only).

**6. Code explanation.** Full code and role table: [22 — section 6](22-V2-BUSINESS-FEATURES.md#6-role-specific-creation-rules-resolvescope).

**7. DB state before / 8. after.** No ticket created (the exception rolls the transaction back; stored files are
deleted).

**9. Equivalent SQL.**

```sql
SELECT * FROM projects WHERE id = 3 LIMIT 1 LOCK IN SHARE MODE;
SELECT EXISTS(SELECT * FROM users INNER JOIN project_user ON users.id = project_user.user_id
              WHERE project_user.project_id = 3 AND users.id = 3) AS `exists`;   -- false
ROLLBACK;
```

**10. Edge cases.** Customer forges `client_id` → ignored. Standalone customer chooses a project → refused (the
select is also disabled in the form). Admin chooses GLOBEX + PAYROLL → "The selected project is not used by this
client organization." Customer of an inactive client → 403 before validation.

**11. Error handling.** `Your organization is inactive, so new tickets cannot be created.` ·
`Your account is not linked to a client organization, so you can only create global tickets.` ·
`Please choose an active client organization.` · `Please choose an active project.` ·
`You can only create tickets for projects you support.` ·
`The selected project is not used by this client organization.`

**12. Interview questions.** Why validate scope in the service and not only in the dropdown? Why is the project
row locked during creation? Why is there no `is_global` column?
(Tests: `TicketScopeTest` — 17 tests including all four admin combinations.)

**13. How to modify.** Allow agents to create client tickets: in the agent branch keep `$clientId` and add an
"agent supports a project the client uses" check; show the client select for agents in `_scope-fields.blade.php`.

---

## 28. Internal notes (V2)

**1. Business requirement.** Staff can add notes customers never see — not in Blade, JSON, email,
notifications, reports, broadcasts or downloads.

**2. Realistic scenario.** Admin Nadia adds an internal note with `payroll-config.pdf` on John's ticket.

**3. Input data.** `POST /tickets/41/comments` with `visibility=internal`, `message`, `attachments[]`.

**4. Expected result.** Comment `visibility = internal`; activities `internal_note_added` and `attachment_added`
with `is_internal = 1`; notification only to staff participants (Sarah); no email; John sees nothing.

**5. Actual implementation.** `TicketPolicy::addInternalNote()`, `TicketCommentController::store()`,
`TicketService::addComment()` / `notifyCommentRecipients()`, `TicketComment::scopeVisibleTo()`,
`TicketActivity::scopeVisibleTo()`, `TicketPolicy::downloadAttachment()`.

**6. Code explanation.** See [22 — section 9](22-V2-BUSINESS-FEATURES.md#9-internal-notes). The key design choice:
internal content is removed **in the SQL query** for customers (`WHERE visibility = 'public'`,
`WHERE is_internal = 0`), so a Blade mistake cannot leak it.

**7–9. DB state and SQL.** See [22 — 15.2](22-V2-BUSINESS-FEATURES.md#152-add-an-internal-note-with-an-attachment).

**10. Edge cases.** Customer sends `visibility=internal` → 403. Agent creator of an internal ticket → notified as
staff creator. Internal note on a closed ticket → 403.

**11. Error handling.** 403 (policy); `Only staff can add internal notes.` (defensive service check); closed message.

**12. Interview questions.** List every channel through which an internal note could leak and how each is closed.
(Tests: `InternalNotesAndAttachmentsTest`.)

**13. How to modify.** Email staff about internal notes: set `$sendEmail = true` for the internal branch
(never for a customer recipient).

---

## 29. Secure attachments (V2)

**1. Business requirement.** Files on ticket creation and on comments; private storage; validated type and size;
authorized downloads; internal-note files staff-only; no orphaned files after a failed database operation.

**2. Realistic scenario.** John attaches `screenshot.png` (300 KB) when creating his ticket; later Emily (another
ACME customer, not a participant) guesses the download URL.

**3. Input data.** `attachments[]` (max 5, each ≤ 5120 KB, pdf/png/jpg/jpeg/txt/docx/xlsx by extension **and**
detected MIME).

**4. Expected result.** File stored as `ticket-attachments/2026/11/<uuid>.png` on the private disk; one
`ticket_attachments` row; Emily gets 403.

**5. Actual implementation.** `AttachmentService` (`validationRules()`, `storeFiles()`, `createRecords()`,
`deleteFiles()`), `TicketAttachmentController::download()`, `TicketPolicy::downloadAttachment()`,
`config/resolvedesk.php`.

**6. Code explanation.** See [22 — section 10](22-V2-BUSINESS-FEATURES.md#10-secure-attachments): files first,
then the transaction, then `deleteFiles()` in `catch`; generated names; `download()` with
`Content-Disposition: attachment` and `nosniff`.

**7. DB state before.** No attachment rows for the ticket.

**8. DB state after.**

| ticket_attachments | ticket_id | ticket_comment_id | uploaded_by | original_name | mime_type | size |
|---|---|---|---|---|---|---|
| new | 41 | NULL | 6 | screenshot.png | image/png | 307200 |

**9. Equivalent SQL.** `INSERT INTO ticket_attachments (ticket_id, ticket_comment_id, uploaded_by, original_name, storage_path, mime_type, size, ...) VALUES (41, NULL, 6, 'screenshot.png', 'ticket-attachments/2026/11/<uuid>.png', 'image/png', 307200, ...);`

**10. Edge cases.** Renamed `.php` → fails `mimes`. 6 MB file → "Each attachment may be at most 5 MB." Database
failure after the file write → file deleted (`test_files_are_deleted_when_the_database_operation_fails`).
Attachment id of another ticket in the URL → 403. File missing on disk → 404 + log.

**11. Error handling.** `Allowed file types: pdf, png, jpg, jpeg, txt, docx, xlsx.`; size message; 403; 404.

**12. Interview questions.** Why can a transaction not roll back a file? Why generated file names? Why not store
in `public/`? What would you add for production? (Antivirus scanning, S3, signed URLs.)

**13. How to modify.** Change limits in `config/resolvedesk.php` / `.env` (`ATTACHMENT_MAX_KB`, `ATTACHMENT_DISK`).

---

## 30. SLA breach reminders (V2)

**1. Business requirement.** When an active ticket passes its deadline, send exactly one reminder per breach: to
the assigned agent, or to all active admins if unassigned. Reset the reminder when the priority changes or the
ticket is reopened.

**2. Realistic scenario.** TK-000043 (Critical, due 11:00, assigned to Fatima) is still Assigned at 11:01.

**3. Input data.** None — the scheduler runs `php artisan tickets:check-sla` every minute.

**4. Expected result.** `sla_notified_at` set, `version` + 1, system activity `sla_breached` (`user_id NULL`),
notification + email to Fatima. The 11:02 run does nothing for this ticket.

**5. Actual implementation.** `app/Console/Commands/CheckSlaBreaches.php`, `routes/console.php`
(`everyMinute()->withoutOverlapping()`), `TicketService::processSlaBreach()`.

**6. Code explanation.** See [22 — section 11](22-V2-BUSINESS-FEATURES.md#11-sla-remaining-time-outcome-breach-reminders)
and [05 — section 15](05-MODULE-WISE-CODE-EXPLANATION.md#15-sla-breach-reminders-v2). The check-and-mark runs on
the locked row inside a transaction, so overlapping runs or servers cannot both notify.

**7–9. DB state and SQL.** See [22 — 15.5](22-V2-BUSINESS-FEATURES.md#155-sla-breach-processing).

**10. Edge cases.** Resolved between the query and the lock → re-check returns false. Agent inactive → admins.
Priority raised later → new deadline, `sla_notified_at` reset → a new breach gets a new reminder. Reopened after
the deadline → immediately overdue, one fresh reminder.

**11. Error handling.** One failed ticket is reported (`report($exception)`) and the loop continues; exit code
`FAILURE` if any failed.

**12. Interview questions.** How do you prevent duplicate reminders? Why `chunkById()`? What does
`withoutOverlapping()` do? When would you need `onOneServer()`?

**13. How to modify.** Frequency in `routes/console.php`; recipients in `processSlaBreach()`.

---

## 31. Idempotency and optimistic locking (V2)

**1. Business requirement.** A double-submitted create form must not create two tickets; an edit or workflow
action based on an outdated page must not silently overwrite someone else's change.

**2. Realistic scenario.** (a) John double-clicks "Submit Ticket" on a slow connection. (b) Admin opens the edit
form (version 3); meanwhile Sarah resolves the ticket (version 4); the admin then saves.

**3. Input data.** (a) Two `POST /tickets` with the same `request_token`. (b) `PUT /tickets/41` with `version=3`.

**4. Expected result.** (a) One ticket, one activity, one set of notifications; the second request shows
"This form was already submitted. Showing ticket TK-000041." (b) Refused with
"This ticket was changed by someone else after you opened the page. Please review the latest version and try
again."; the form keeps the typed input; JSON callers get HTTP 409.

**5. Actual implementation.** (a) `TicketService::createTicket()` + `findByRequestToken()` + `replayOrReject()` +
UNIQUE `(created_by, request_token)`. (b) `Ticket::ensureVersion()`, `TicketService::updateTicket()` conditional
UPDATE, `saveNewVersion()`, `App\Exceptions\TicketConflictException::render()`.

**6. Code explanation.** See [05 — 4.1 and 4.4](05-MODULE-WISE-CODE-EXPLANATION.md#41-operation-create-a-ticket).

```php
public function render(Request $request)
{
    if ($request->expectsJson()) {
        return response()->json(['message' => $this->getMessage()], 409);
    }

    return back()->withInput()->with('error', $this->getMessage());
}
```

**7. DB state before.** (b) ticket 41 version 4 (resolved).

**8. DB state after.** (b) unchanged.

**9. Equivalent SQL.** (b) `UPDATE tickets SET ..., version = version + 1 WHERE id = 41 AND version = 3 AND status <> 'closed';` → 0 rows.

**10. Edge cases.** Same token + different content → conflict. Token is scoped per creator
(`test_request_token_is_scoped_to_the_creator`). Comments do not use `version` (adding a comment never overwrites
data).

**11. Error handling.** Conflict flash / 409; `The form expired. Please reload the page and try again.` (missing token).

**12. Interview questions.** Optimistic vs pessimistic locking — where does the project use each? Why is the
UNIQUE index needed in addition to the first lookup? (Tests: `ConsistencyTest`, `MySqlConcurrencyTest`.)

**13. How to modify.** Require `version` on workflow buttons too: change `nullable` to `required` in the
controller rules (the forms already send it).

---

## 32. Reports and CSV export (V2)

**1. Business requirement.** Admins filter tickets (project/global, client/internal, agent/unassigned, status,
priority, category, date range), see SLA statistics, and export exactly the same tickets to CSV safely.

**2. Realistic scenario.** Management asks: "How did HRMS do on SLA in November?"

**3. Input data.** `GET /reports?project_id=1&date_from=2026-11-01&date_to=2026-11-30`, then the Export link.

**4. Expected result.** Page shows Total, Active, Overdue now, Resolved within/after SLA, SLA compliance %; CSV
contains the same tickets, formulas neutralized.

**5. Actual implementation.** `ReportController::index()` / `export()` / `safeCsvValue()` / `slaResult()`,
`TicketController::applyFilters()`, `Ticket::slaSummary()`; `throttle:exports`.

**6. Code explanation.** See [22 — section 13](22-V2-BUSINESS-FEATURES.md#13-reports-and-csv-export).
SLA compliance % = resolved on/before `due_at` ÷ all Resolved/Closed × 100; `null` ("—") when nothing is
resolved (no division by zero). Date range = `created_at` date, both ends inclusive.

**7–8. DB state.** Read-only.

**9. Equivalent SQL.** See [22 — 15.6](22-V2-BUSINESS-FEATURES.md#156-csv-export).

**10. Edge cases.** No resolved tickets → "—". A subject starting with `=` → exported as `'=...`. Very large
export → streamed in chunks of 500. 6th export within a minute → 429.

**11. Error handling.** Invalid filters → redirect back with errors; 429 page.

**12. Interview questions.** How do you guarantee the CSV matches the screen? What is CSV formula injection?
Why `chunkById()` instead of `get()`?

**13. How to modify.** New filter: add it once in `TicketController::applyFilters()` (+ validation in both
`index()` and `ReportController::filteredQuery()`, + the inputs in both views).
