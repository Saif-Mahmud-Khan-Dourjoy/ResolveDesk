# 14 — Queue and Email Guide

This guide explains how ResolveDesk sends ticket emails **in the background** using Laravel's database queue.
Everything described is **implemented**, except sections marked *Future improvement*.

Files covered:

| File | Role |
|---|---|
| `app/Services/TicketService.php` → `notifyUser()`, `queueEmail()` | **Producer**: decides when an email is needed and dispatches the job after commit |
| `app/Jobs/SendTicketEmail.php` | The one reusable **job** (`ShouldQueue`, 3 tries, 10 s backoff, `failed()`) |
| `app/Mail/TicketUpdateMail.php` | The one reusable **Mailable** |
| `resources/views/emails/ticket-update.blade.php` | The HTML email template |
| `database/migrations/0001_01_01_000002_create_jobs_table.php` | `jobs` and `failed_jobs` tables |
| `config/queue.php`, `config/mail.php`, `.env` | `QUEUE_CONNECTION=database`, `MAIL_MAILER=log` |
| `tests/Feature/QueueAndEmailTest.php` | 10 tests |

Related: [Notification system](13-NOTIFICATION-SYSTEM-GUIDE.md), [Business logic](06-BUSINESS-LOGIC-GUIDE.md),
[Debugging](11-DEBUGGING-GUIDE.md).

---

## 1. Why email should be asynchronous

Sending an email means connecting to an SMTP server over the internet. That can take 1–5 seconds, and it can
fail (wrong password, server down, timeout).

If the controller sent the email **during the web request**:

- The admin clicking "Assign" would wait several seconds.
- If SMTP failed, the request could show an error even though the ticket was already assigned.

So the web request only writes a small **job row** into the `jobs` table (a few milliseconds) and returns. A
separate process — the **queue worker** — sends the email later.

### Synchronous vs asynchronous

| | Synchronous | Asynchronous (what we do) |
|---|---|---|
| When does the work run? | Immediately, inside the request | Later, in another process |
| Does the user wait? | Yes | No |
| Failure affects the request? | Yes | No — the job is retried on its own |
| In ResolveDesk | In-app notifications (`TicketNotification`, database channel) | Emails (`SendTicketEmail`) |
| In tests | `phpunit.xml` sets `QUEUE_CONNECTION=sync`, so jobs run immediately (useful for testing) | `.env` uses `database` |

> **বাংলায়:** Synchronous মানে কাজটা এখনই, একই request-এর ভেতরে হবে এবং ব্যবহারকারী অপেক্ষা করবে। Asynchronous মানে
> কাজটা একটা তালিকায় (queue) রেখে দেওয়া হয়, আর আলাদা একটা process (queue worker) পরে সেটা করে। ইমেইল পাঠানো ধীর
> এবং ব্যর্থ হতে পারে, তাই আমরা এটাকে queue-তে পাঠাই — ব্যবহারকারীকে অপেক্ষা করতে হয় না।

### Producer, queue, consumer

```mermaid
flowchart LR
    A["Web request: TicketService (producer)"] -->|"SendTicketEmail::dispatch()"| B[("jobs table (queue)")]
    B -->|"php artisan queue:work (consumer)"| C["SendTicketEmail::handle()"]
    C --> D["Mail::to()->send(TicketUpdateMail)"]
    D --> E["log mailer: storage/logs/laravel.log or SMTP: Gmail"]
    C -->|"fails 3 times"| F[("failed_jobs table")]
```

| Term | In ResolveDesk |
|---|---|
| **Producer** | `TicketService::queueEmail()` — creates jobs |
| **Queue** | The `jobs` table (`QUEUE_CONNECTION=database`), queue name `default` |
| **Job** | `App\Jobs\SendTicketEmail` — one unit of work |
| **Payload** | The JSON stored in `jobs.payload` (job class + serialized properties) |
| **Consumer / worker** | `php artisan queue:work` — a long-running PHP process that reads the table |

---

## 2. Database queue configuration

```env
# .env
QUEUE_CONNECTION=database
MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@resolvedesk.test"
MAIL_FROM_NAME="${APP_NAME}"
```

`config/queue.php` (Laravel default, unchanged):

```php
'database' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
    'after_commit' => false,
],
```

- `retry_after` = 90: if a worker took a job and did not finish it within 90 seconds (e.g. the worker crashed),
  another worker may pick it up again. Important for the duplicate-delivery discussion below.
- `after_commit` = false: the connection does **not** wait for commits automatically. ResolveDesk handles this
  explicitly with `DB::afterCommit()` (section 6).

The database queue needs no extra software (no Redis), which fits XAMPP on Windows.

---

## 3. The `jobs` and `failed_jobs` tables

```php
Schema::create('jobs', function (Blueprint $table) {
    $table->id();
    $table->string('queue')->index();
    $table->longText('payload');
    $table->unsignedTinyInteger('attempts');
    $table->unsignedInteger('reserved_at')->nullable();
    $table->unsignedInteger('available_at');
    $table->unsignedInteger('created_at');
});

Schema::create('failed_jobs', function (Blueprint $table) {
    $table->id();
    $table->string('uuid')->unique();
    $table->text('connection');
    $table->text('queue');
    $table->longText('payload');
    $table->longText('exception');
    $table->timestamp('failed_at')->useCurrent();
});
```

| `jobs` column | Meaning |
|---|---|
| `queue` | Queue name (`default`) |
| `payload` | JSON describing the job (see section 7) |
| `attempts` | How many times a worker has tried it |
| `reserved_at` | Unix time when a worker took it; `NULL` = waiting |
| `available_at` | Unix time from when it may run (used for backoff delays) |
| `created_at` | Unix time when it was dispatched (integers, not DATETIME) |

A successful job is **deleted** from `jobs`. A job that fails all attempts is **moved** to `failed_jobs` with the
full exception text.

---

## 4. The job — `app/Jobs/SendTicketEmail.php`

```php
namespace App\Jobs;

use App\Mail\TicketUpdateMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendTicketEmail implements ShouldQueue
{
    use Queueable;

    /** Try the job at most 3 times before moving it to failed_jobs. */
    public int $tries = 3;

    /** Wait 10 seconds before each retry. */
    public int $backoff = 10;

    public function __construct(
        public string $recipientEmail,
        public string $recipientName,
        public array $emailData,
    ) {
    }

    /** Runs inside the queue worker process, not during the web request. */
    public function handle(): void
    {
        Mail::to($this->recipientEmail)->send(
            new TicketUpdateMail($this->recipientName, $this->emailData)
        );
    }

    /** Called once after the final failed attempt (the job is then stored in failed_jobs). */
    public function failed(?Throwable $exception): void
    {
        Log::error('Ticket email failed permanently', [
            'recipient' => $this->recipientEmail,
            'ticket_number' => $this->emailData['ticket_number'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
```

### `ShouldQueue`

An **interface** with no methods — a marker. When Laravel dispatches a class that implements `ShouldQueue`, it
puts it on the queue instead of running it now. **If you remove `implements ShouldQueue`**, `dispatch()` runs
`handle()` immediately inside the web request (synchronous) — the admin would wait for SMTP, and an SMTP error
would surface in the request (though `queueEmail()`'s try/catch would log it).

### `Queueable`

`Illuminate\Foundation\Queue\Queueable` is a trait that bundles four traits:

| Trait | Gives you |
|---|---|
| `Dispatchable` | the static `SendTicketEmail::dispatch(...)` method |
| `InteractsWithQueue` | `$this->attempts()`, `$this->release()`, `$this->fail()` inside the job |
| `Illuminate\Bus\Queueable` | `->onQueue()`, `->delay()`, `->onConnection()` |
| `SerializesModels` | Eloquent models in properties are stored as id only and re-loaded by the worker |

### Constructor

Constructor property promotion (`public string $recipientEmail`) creates public properties. The constructor runs
in the **web request** (when dispatching). The values are then serialized into the payload. The constructor
does **not** run again in the worker — the worker unserializes the object.

Design choice: only **simple values** (two strings + a small array), not a `Ticket` model. Reasons:

- Small payload.
- The email shows the status **at the time of the event** ("Assigned"), even if the ticket changes again before
  the worker runs.
- No database query is needed in the worker to load the ticket.

`$emailData` keys: `ticket_id`, `ticket_number`, `subject`, `status`, `event_title`, `event_message`.

### `handle()`

Runs only in the worker. `Mail::to(...)->send(...)` builds the Mailable and passes it to the configured mailer
(`log` or `smtp`). If it throws an exception, the attempt fails and the job is retried.

### `$tries` and `$backoff` (retry)

- `$tries = 3` → maximum three attempts in total.
- `$backoff = 10` → after a failed attempt, the job is released back to `jobs` with `available_at = now + 10 s`.

Timeline if SMTP is down:

| Time | Event |
|---|---|
| 0 s | Attempt 1 fails → row back in `jobs`, `attempts = 1`, available in 10 s |
| ~10 s | Attempt 2 fails → `attempts = 2`, available in 10 s |
| ~20 s | Attempt 3 fails → `failed()` is called → row moved to `failed_jobs` |

Tested: `test_job_retry_configuration` asserts `tries === 3` and `backoff === 10`.

### `failed()`

Called **once**, after the last attempt. It writes a `Log::error` line to `storage/logs/laravel.log` so a developer
can see which recipient and ticket were affected. `?Throwable` = may be null; `?->` (nullsafe operator) avoids an
error if `$exception` is null.

> **বাংলায়:** Retry মানে job ব্যর্থ হলে আবার চেষ্টা করা; backoff মানে আবার চেষ্টা করার আগে কতক্ষণ অপেক্ষা করবে (এখানে
> ১০ সেকেন্ড)। তিনবার ব্যর্থ হলে job টা `failed_jobs` টেবিলে চলে যায় এবং `failed()` method log-এ error লিখে রাখে, যাতে পরে
> দেখে আবার চালানো (retry) যায়।

---

## 5. The Mailable — `app/Mail/TicketUpdateMail.php`

```php
class TicketUpdateMail extends Mailable
{
    public function __construct(
        public string $recipientName,
        public array $emailData,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[' . $this->emailData['ticket_number'] . '] ' . $this->emailData['event_title'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-update',
            with: [
                'recipientName' => $this->recipientName,
                'ticketNumber' => $this->emailData['ticket_number'],
                'ticketSubject' => $this->emailData['subject'],
                'eventTitle' => $this->emailData['event_title'],
                'eventMessage' => $this->emailData['event_message'],
                'statusLabel' => $this->emailData['status'],
                // The link only opens the ticket after login + TicketPolicy@view,
                // so the email itself never grants access.
                'ticketUrl' => route('tickets.show', $this->emailData['ticket_id']),
            ],
        );
    }
}
```

| Part | Meaning |
|---|---|
| `extends Mailable` | Laravel's base email class. |
| `envelope()` | Email headers. Subject example: `[TK-000004] New ticket assigned`. `subject:` is a PHP 8 **named argument**. The "from" address comes from `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`. |
| `content()` | Which Blade view to render (`emails.ticket-update` = `resources/views/emails/ticket-update.blade.php`) and which variables to pass (`with`). |
| `route('tickets.show', ...)` | Builds an absolute URL using `APP_URL` (`http://127.0.0.1:8000`). In the worker there is no HTTP request, so `APP_URL` must be correct. |
| Not `ShouldQueue` | The Mailable is **not** queued itself, because the job is already running in the queue. Queuing it again would create a second job. |

Tested: `test_job_sends_the_ticket_update_mail` (uses `Mail::fake()` and checks recipient + subject).

## 6. The email Blade template

`resources/views/emails/ticket-update.blade.php` (shortened — read the full file):

```blade
<body style="margin:0; padding:0; background:#f5f6fa; font-family: 'Segoe UI', Arial, sans-serif; color:#1f2937;">
<table role="presentation" width="100%" ...>
    ...
    <span style="... background:#4f46e5; color:#ffffff; ...">RD</span>
    <span style="font-size:18px; font-weight:bold; margin-left:8px;">ResolveDesk</span>
    ...
    <p style="margin:0 0 16px;">Hello {{ $recipientName }},</p>
    <h1 style="font-size:20px; margin:0 0 8px;">{{ $eventTitle }}</h1>
    <p style="margin:0 0 20px; color:#4b5563;">{{ $eventMessage }}</p>
    ... Ticket {{ $ticketNumber }} / Subject {{ $ticketSubject }} / Status {{ $statusLabel }} ...
    <a href="{{ $ticketUrl }}" style="...">View ticket</a>
    <p ...>You need to sign in to view the ticket details.</p>
    ...
    This is an automated message from ResolveDesk. Please do not reply to this email.
```

- **Tables + inline styles:** many email clients (Outlook, Gmail) ignore `<style>` blocks and modern CSS layout,
  so email HTML traditionally uses tables and inline `style=""`.
- **`{{ }}` escapes HTML**, so a ticket subject containing `<script>` is shown as text, not executed.
- **Minimal data:** only number, subject, status and the event message — no description, no comments. Details
  require logging in, and `TicketPolicy::view` decides access (an email link is not a permission).

Tested: `test_email_template_renders_ticket_information`.

---

## 7. Dispatching after commit — `TicketService::queueEmail()`

```php
private function queueEmail(User $recipient, Ticket $ticket, string $title, string $message): void
{
    $recipientEmail = $recipient->email;
    $recipientName = $recipient->name;
    $emailData = [
        'ticket_id' => $ticket->id,
        'ticket_number' => $ticket->ticket_number,
        'subject' => $ticket->subject,
        'status' => $ticket->statusLabel(),
        'event_title' => $title,
        'event_message' => $message,
    ];

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
}
```

### Step by step

1. Copy simple values **now** (email, name, ticket data, `statusLabel()` at this moment).
2. `DB::afterCommit(function () use (...) { ... })` — register a **closure** (anonymous function). `use (...)` copies
   the outer variables into the closure.
3. If we are inside a transaction (we always are — `queueEmail()` is only called from `notifyUser()`, which runs
   inside `DB::transaction()`), Laravel **stores** the closure and runs it only after the outermost transaction
   **commits**. Outside any transaction, Laravel runs it immediately.
4. `SendTicketEmail::dispatch(...)` creates the job and inserts the `jobs` row.
5. `try/catch (Throwable $exception)` — if inserting the job fails (e.g. `jobs` table missing, DB connection
   dropped), we **log** the error instead of throwing.

### Why this is equivalent to `afterCommit()`

MASTER_PROMPT asks for "`afterCommit()` or an equivalent straightforward implementation". Laravel also offers
`SendTicketEmail::dispatch(...)->afterCommit()` and the job property `$afterCommit = true`. Those work the same way:
the queue waits until the open transaction commits. `DB::afterCommit()` was chosen because it also lets us wrap
the dispatch in our **own** `try/catch` + `Log::error` (with `->afterCommit()`, an exception during the delayed
dispatch would happen inside Laravel's commit handling and bubble up to the controller, so the user could see an
error page for an operation that was actually committed).

### What happens on rollback?

If anything inside `DB::transaction()` throws (e.g. a `ValidationException` from `ensureActionAllowed()`), Laravel
runs `ROLLBACK` and **discards** the stored callbacks. So:

- no ticket change, no activity rows, no notification row (all rolled back by the database), and
- **no email job** (the closure never runs).

This prevents the classic bug "the user got an email about an assignment that never happened".
Tested: `test_no_email_job_is_dispatched_when_the_transaction_rolls_back` wraps `assignAgent()` in an outer
transaction that throws, then asserts `Queue::assertNothingPushed()` and that `notifications` and
`ticket_activities` are empty.

### What happens if email fails after commit?

The ticket change is already committed and must **not** be reversed.

- Dispatch fails → `catch` → `Log::error('Could not queue ticket email', ...)`. The user still sees
  "Ticket assigned to …" because the assignment really succeeded.
- Sending fails in the worker → retries → `failed_jobs` + `failed()` log.

Tested: `test_email_failure_does_not_reverse_the_committed_assignment` (with the `sync` queue the job runs right
after commit; `Mail::to` throws "SMTP server unavailable"; the response still has the success flash, the ticket is
assigned and the agent has 1 notification).

```mermaid
sequenceDiagram
    participant TS as "TicketService"
    participant DB as "Database"
    participant Q as "jobs table"
    TS->>DB: BEGIN
    TS->>DB: UPDATE ticket, INSERT activity, INSERT notification
    TS->>TS: DB::afterCommit(closure) - stored, not run
    alt everything OK
        TS->>DB: COMMIT
        DB-->>TS: committed, Laravel runs stored closures
        TS->>Q: INSERT job SendTicketEmail
    else exception
        TS->>DB: ROLLBACK
        Note over TS: stored closures are discarded, no job
    end
```

> **বাংলায়:** Transaction মানে কয়েকটা database কাজ একসাথে — হয় সবগুলো সফল (commit), নয়তো একটাও না (rollback)।
> `DB::afterCommit()` ইমেইল পাঠানোর কাজটা আলাদা করে রেখে দেয় এবং শুধু commit সফল হলেই চালায়। Rollback হলে সেই কাজ
> ফেলে দেওয়া হয়, তাই যে assignment আসলে হয়নি তার জন্য কখনো ইমেইল যাবে না।

**Limitation (honest):** between COMMIT and the job insert there is a tiny window. If the PHP process crashes
exactly there, the ticket is saved but the email job is never created. The professional fix is a
**transactional outbox** (*future improvement only*, explicitly not implemented): write an "outbox" row inside the
same transaction and let a separate process turn outbox rows into jobs.

---

## 8. Serialization and the payload

**Serialization** = turning a PHP object into a string so it can be stored in a database and turned back into the
same object later (**unserialization**) by another process.

This is the real output of PHP's `serialize()` for a `SendTicketEmail` (generated locally with PHP 8.4.28):

```text
O:24:"App\Jobs\SendTicketEmail":3:{s:14:"recipientEmail";s:30:"michael.agent@resolvedesk.test";s:13:"recipientName";s:13:"Michael Brown";s:9:"emailData";a:6:{s:9:"ticket_id";i:4;s:13:"ticket_number";s:9:"TK-000004";s:7:"subject";s:32:"API returns 504 for bulk uploads";s:6:"status";s:8:"Assigned";s:11:"event_title";s:19:"New ticket assigned";s:13:"event_message";s:46:"Ticket TK-000004 ... has been assigned to you.";}}
```

How to read it: `O:24:"App\Jobs\SendTicketEmail":3:{...}` = an **O**bject whose class name is 24 characters long,
with 3 properties. `s:14:"recipientEmail"` = a **s**tring of 14 characters. `a:6:{...}` = an **a**rray with 6
items. `i:4` = **i**nteger 4.

Laravel wraps that string in a JSON payload stored in `jobs.payload` (structure from
`Illuminate\Queue\Queue::createObjectPayload()`; values illustrative):

```json
{
  "uuid": "9b1c…",
  "displayName": "App\\Jobs\\SendTicketEmail",
  "job": "Illuminate\\Queue\\CallQueuedHandler@call",
  "maxTries": 3,
  "maxExceptions": null,
  "failOnTimeout": false,
  "backoff": 10,
  "timeout": null,
  "retryUntil": null,
  "data": {
    "commandName": "App\\Jobs\\SendTicketEmail",
    "command": "O:24:\"App\\Jobs\\SendTicketEmail\":3:{…}",
    "batchId": null
  },
  "createdAt": 1795598785
}
```

The worker reads the row, `unserialize()`s `data.command` back into a `SendTicketEmail` object, and calls
`handle()`. `maxTries` and `backoff` are copied from our `$tries` and `$backoff` properties.

Consequences:

- **Change the job's properties carefully.** Jobs already waiting in `jobs` were serialized with the old
  properties.
- **Do not put closures or open resources** (DB connections, file handles) into job properties — they cannot be
  serialized.
- If we had passed a `Ticket` model, `SerializesModels` would store only `App\Models\Ticket` + id, and the worker
  would reload the **current** row (a different status than at event time).

> **বাংলায়:** Serialization মানে একটা PHP object-কে string-এ রূপান্তর করা, যাতে database-এ রাখা যায়। Queue worker
> পরে সেই string থেকে আবার একই object বানায় (unserialize) এবং `handle()` চালায়। তাই job-এ ছোট, সাধারণ data (string,
> array) রাখা সবচেয়ে নিরাপদ।

---

## 9. The queue worker

### Commands

Always set PHP 8.4 first (see [Setup guide](02-SETUP-GUIDE.md)):

```bash
export PATH="/c/php-8.4.28:$PATH"     # Git Bash
php artisan queue:work                 # start the consumer (keeps running; Ctrl+C to stop)
php artisan queue:work --stop-when-empty   # process everything that is waiting, then exit
php artisan queue:failed               # list rows in failed_jobs
php artisan queue:retry all            # move failed jobs back to the queue (or: queue:retry <uuid>)
php artisan queue:restart              # tell running workers to exit after their current job
php artisan queue:flush                # delete ALL failed jobs (careful)
```

How `queue:work` behaves: it loops forever. It reserves the oldest available row (sets `reserved_at`, increments
`attempts`), runs the job, and deletes the row on success. When the table is empty it sleeps 3 seconds
(`--sleep=3` default) and checks again.

### What happens when the worker is stopped?

Required behaviour (MASTER_PROMPT §10.5) and how ResolveDesk meets it:

| Requirement | Why it works |
|---|---|
| Ticket operations keep working | The web request only inserts a `jobs` row; it never waits for a worker. |
| In-app notifications keep working | `TicketNotification` is **not** queued; it is inserted directly in the transaction. |
| Email jobs remain pending | Rows stay in `jobs` with `reserved_at = NULL`. Nothing is lost. |
| Pending jobs are processed when the worker starts | `queue:work` reads all waiting rows, oldest first. |

### Worker restart — why it matters

The worker is a **long-running process**: it loads your PHP code **once** at start. If you edit
`SendTicketEmail.php` or `TicketUpdateMail.php`, a running worker keeps using the **old** code. After a code
change, run `php artisan queue:restart` (it sets a flag in the cache; each worker finishes its current job and
exits) and start the worker again. In production a process manager (Supervisor on Linux) restarts it
automatically. On this Windows dev PC you simply press Ctrl+C and run `queue:work` again.

If the worker is killed **in the middle** of a job, the row stays reserved. After `retry_after` (90 s) it becomes
available again and is retried — the email might already have been sent (see section 11).

---

## 10. Mail drivers: log vs SMTP

### Log mailer (default, implemented)

`MAIL_MAILER=log` writes the complete email (headers + HTML) into `storage/logs/laravel.log` instead of sending
it. The whole application works without any SMTP account, which is ideal for development and interviews.

### SMTP with Gmail (documented, not verified)

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=your.address@gmail.com
MAIL_PASSWORD=your-16-character-app-password
MAIL_FROM_ADDRESS=your.address@gmail.com
MAIL_FROM_NAME="ResolveDesk"
```

- **SMTP** (Simple Mail Transfer Protocol) is the standard protocol for sending email. Port 587 uses STARTTLS
  encryption.
- **Google App Password:** Gmail does not accept your normal password from apps. Turn on **2-Step Verification**
  on the Google account, then create an **App Password** (Google Account → Security → App passwords). Google shows
  a 16-character password; put it in `MAIL_PASSWORD`.
- Put credentials **only in `.env`** (which is git-ignored). Never in `config/mail.php`, never in code, never
  committed.
- After changing `.env`: `php artisan config:clear` and **restart the queue worker** (it read the old config at
  start).

Gmail SMTP delivery was **not verified** in this project (no credentials were available; see
DEVELOPMENT_PROGRESS.md).

---

## 11. Duplicate delivery and idempotency (conceptual)

Queues give **at-least-once** delivery, not exactly-once. Ways an email can be sent twice:

1. SMTP accepted the email, but the connection broke before the success answer → exception → retry → sent again.
2. The worker crashed after sending but before deleting the row → after `retry_after` the job runs again.
3. An admin runs `queue:retry` on a job that actually partly succeeded.

ResolveDesk **accepts** this small risk (it is documented, not solved). For a ticket update email, a rare
duplicate is harmless.

**Idempotency** means "running the same operation twice has the same effect as running it once". How it *could*
be added (*future improvement*, not implemented):

- Give each email a unique key (e.g. notification id + event type) and store "sent" keys in a table; the job
  checks the table before sending and skips if already sent.
- Laravel's `ShouldBeUnique` prevents duplicate jobs from being **queued**, but it does not stop a retry after a
  partial success.

> **বাংলায়:** Queue সাধারণত "অন্তত একবার" কাজটা করার নিশ্চয়তা দেয়, "ঠিক একবার" নয়। তাই retry-র কারণে মাঝে মাঝে একই
> ইমেইল দুইবার যেতে পারে। Idempotency মানে একই কাজ দুইবার করলেও ফলাফল একবার করার মতোই থাকে — যেমন পাঠানো ইমেইলের
> একটা unique key রেখে দ্বিতীয়বার পাঠানো বন্ধ করা। এটা আমাদের প্রজেক্টে শুধু ধারণা হিসেবে ব্যাখ্যা করা হয়েছে।

---

## 12. Which events send email

From `TicketService` (`$sendEmail` argument of `notifyUser()`):

| Method | Event | Email? |
|---|---|---|
| `createTicket()` | created | Yes (to every active admin, except an admin who created the ticket) |
| `performAssignment()` | assigned / reassigned | Yes (to the agent) |
| `resolve()` | resolved | Yes (to the creator) |
| `reopen()` | reopened | Yes (to the agent) |
| `close()` | closed | No |
| `addComment()` | comment | No |
| `updateTicket()`, `startWork()` | — | No notification at all |

The same skip rules apply as for in-app notifications: no email to inactive users or to the actor.
Tested: `test_assignment_queues_one_email_to_the_assigned_agent`,
`test_resolution_emails_the_creator_but_closure_sends_no_email`,
`test_new_ticket_notifies_and_emails_every_active_admin`, `test_admin_creating_a_ticket_is_not_notified_about_it`.
The `createTicket()` → admins email was added at the project owner's request, beyond the original
MASTER_PROMPT §9.2 matrix.

---

## 13. What was actually verified (DEVELOPMENT_PROGRESS.md)

- With **no worker running**, two `SendTicketEmail` jobs waited in the `jobs` table.
- `php artisan queue:work --stop-when-empty` processed both: **0 pending, 0 failed**.
- `storage/logs/laravel.log` contained both emails with the correct To, Subject and body.
- All tests in `tests/Feature/QueueAndEmailTest.php` pass, including the rollback and email-failure tests.
- **Not verified:** real Gmail SMTP delivery.

Try it yourself:

```bash
export PATH="/c/php-8.4.28:$PATH"
# 1. Do NOT start a worker. Log in as admin and assign an Open ticket.
php artisan tinker --execute "echo DB::table('jobs')->count();"     # 1 (or more)
# 2. Process the queue:
php artisan queue:work --stop-when-empty
php artisan tinker --execute "echo DB::table('jobs')->count();"     # 0
# 3. Look at the end of storage/logs/laravel.log: Subject: [TK-0000xx] New ticket assigned
```

## 14. Failed-job debugging

| Step | Command / place |
|---|---|
| Are jobs waiting? | `php artisan tinker --execute "echo DB::table('jobs')->count();"` |
| Is a worker running? | Look for a terminal running `queue:work`. No worker = jobs wait forever. |
| What failed? | `php artisan queue:failed` (shows uuid, class, failed time) |
| Why? | `failed_jobs.exception` column, and `storage/logs/laravel.log` → "Ticket email failed permanently" (from `failed()`) or "Could not queue ticket email" (from `queueEmail()`) |
| Fix and retry | Fix the cause (e.g. `.env` SMTP password), `php artisan config:clear`, restart worker, `php artisan queue:retry all` |
| Code change not used? | `php artisan queue:restart`, then start the worker again |

Common causes: wrong SMTP password / no App Password, `APP_URL` wrong (bad links), worker started with the old
global PHP version (always check `php -v`), a typo in a `$emailData` key (undefined array key error in the
Mailable).

## 15. Practice modifications

1. Email the agent when a ticket is closed: in `TicketService::close()`, change the last argument of
   `notifyUser()` from `false` to `true`. Then update `test_resolution_emails_the_creator_but_closure_sends_no_email`
   (it currently asserts that closing sends no email).
2. Change retries to 5 with 30 s backoff: edit `$tries` and `$backoff`, update `test_job_retry_configuration`,
   restart the worker.
3. Add the ticket priority to the email: add `'priority' => $ticket->priorityLabel()` in `queueEmail()`, pass it in
   `TicketUpdateMail::content()`, show it in the template.
