<?php

namespace App\Jobs;

use App\Mail\TicketUpdateMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ONE reusable queued job that sends a ticket update email.
 *
 * Producer: TicketNotifier dispatches this job (after the transaction commits) (stores a row in the "jobs" table).
 * Consumer: `php artisan queue:work` picks the row up and calls handle().
 *
 * The constructor only receives simple values (strings and a small array).
 * Laravel serializes these properties into the job payload (JSON in jobs.payload),
 * so the payload is small and does not depend on model state at send time.
 */
class SendTicketEmail implements ShouldQueue
{
    use Queueable;

    /** Try the job at most 3 times before moving it to failed_jobs. */
    public int $tries = 3;

    /** Wait 10 seconds before each retry. */
    public int $backoff = 10;

    /**
     * V2: kill the attempt after 60 seconds (e.g. a hanging SMTP server).
     * Must be SHORTER than retry_after (90 s in config/queue.php), otherwise a
     * second worker could start the same job while the first is still running.
     */
    public int $timeout = 60;

    /**
     * @param  array  $emailData  keys: ticket_id, ticket_number, subject, status, project,
     *                            event_title, event_message, delivery_key
     */
    public function __construct(
        public string $recipientEmail,
        public string $recipientName,
        public array $emailData,
    ) {}

    /**
     * Runs inside the queue worker process, not during the web request.
     *
     * V2 duplicate protection (practical, not perfect): delivery_key is the id of
     * the in-app notification this email belongs to. After a successful send we
     * remember it in the cache for 2 days; a retry of the same job then skips
     * sending. A crash exactly between "SMTP accepted" and "cache saved" can still
     * cause one duplicate - queues are at-least-once and SMTP has no exactly-once.
     */
    public function handle(): void
    {
        $deliveryKey = $this->emailData['delivery_key'] ?? null;
        $cacheKey = $deliveryKey ? 'ticket-email-sent:' . $deliveryKey : null;

        if ($cacheKey !== null && Cache::has($cacheKey)) {
            Log::info('Ticket email already sent; skipping duplicate attempt', ['delivery_key' => $deliveryKey]);

            return;
        }

        Mail::to($this->recipientEmail)->send(
            new TicketUpdateMail($this->recipientName, $this->emailData)
        );

        if ($cacheKey !== null) {
            Cache::put($cacheKey, true, now()->addDays(2));
        }
    }

    /** Called once after the final failed attempt (the job is then stored in failed_jobs). */
    public function failed(?Throwable $exception): void
    {
        Log::error('Ticket email failed permanently', [
            // No email address here: technical logs should not collect personal data.
            'ticket_id' => $this->emailData['ticket_id'] ?? null,
            'ticket_number' => $this->emailData['ticket_number'] ?? null,
            'error' => $exception?->getMessage(),
        ]);
    }
}
