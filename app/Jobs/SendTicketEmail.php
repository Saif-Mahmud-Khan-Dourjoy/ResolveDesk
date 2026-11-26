<?php

namespace App\Jobs;

use App\Mail\TicketUpdateMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ONE reusable queued job that sends a ticket update email.
 *
 * Producer: TicketService dispatches this job (stores a row in the "jobs" table).
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
     * @param  array  $emailData  keys: ticket_id, ticket_number, subject, status, event_title, event_message
     */
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
