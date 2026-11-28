<?php

namespace App\Services;

use App\Events\UserNotificationCreated;
use App\Jobs\SendTicketEmail;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketNotification;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * V2: everything that happens when a ticket event should reach a person.
 *
 * For one recipient, notify() does:
 *   1. In-app notification  -> INSERT into notifications, inside the current
 *      transaction (rolled back together with the ticket change). This is the
 *      durable source of truth.
 *   2. After COMMIT only:
 *      a. real-time signal -> UserNotificationCreated broadcast to Reverb
 *      b. email (optional) -> SendTicketEmail job pushed to the database queue
 *
 * Side effects (2a, 2b) never run if the transaction rolls back, and a failure in
 * them is logged, never shown to the user as a failed ticket operation.
 *
 * "Holding" side effects: while TicketAssignmentService holds its Cache::lock(),
 * we do not want network calls (Reverb) inside the lock. holdSideEffects() makes
 * the after-commit callbacks wait in a list until releaseSideEffects() is called
 * after the lock is released.
 */
class TicketNotifier
{
    private bool $holding = false;

    /** @var array<Closure> */
    private array $heldSideEffects = [];

    /**
     * Creates the notification for ONE recipient.
     * Skips: missing user, inactive user, and the person who performed the action
     * ($actor = null means the SYSTEM acted, e.g. the SLA scheduler; nobody is skipped).
     */
    public function notify(?User $recipient, Ticket $ticket, ?User $actor, string $eventType, string $title, string $message, bool $sendEmail): void
    {
        if ($recipient === null || ! $recipient->is_active) {
            return;
        }

        if ($actor !== null && $recipient->id === $actor->id) {
            return; // Do not notify people about their own actions.
        }

        $notification = new TicketNotification($ticket, $eventType, $title, $message);
        // We choose the UUID ourselves so we know which notifications.id row was created
        // (Laravel keeps an id that is already set).
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

    /** Every active administrator except the actor (used for new tickets and unassigned SLA breaches). */
    public function notifyAdmins(Ticket $ticket, ?User $actor, string $eventType, string $title, string $message, bool $sendEmail): void
    {
        $admins = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->get();

        foreach ($admins as $admin) {
            $this->notify($admin, $ticket, $actor, $eventType, $title, $message, $sendEmail);
        }
    }

    /**
     * Pushes the email job to the queue ONLY after the transaction commits.
     * The job receives plain values (strings + small array), never models.
     */
    private function queueEmail(User $recipient, Ticket $ticket, string $title, string $message, string $deliveryKey): void
    {
        $recipientEmail = $recipient->email;
        $recipientName = $recipient->name;
        $emailData = [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'status' => $ticket->statusLabel(),
            'project' => $ticket->project_id ? $ticket->project?->displayName() : null,
            'event_title' => $title,
            'event_message' => $message,
            'delivery_key' => $deliveryKey, // = notification id; lets a retried job skip a sent email
        ];

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
    }

    /**
     * Real-time signal to the recipient's private channel. If Reverb is down,
     * the notification is still in the database and the browser's polling
     * fallback (public/js/notifications.js) will show it.
     */
    private function broadcast(int $recipientId, string $notificationId): void
    {
        try {
            event(new UserNotificationCreated($recipientId, $notificationId));
        } catch (Throwable $exception) {
            Log::warning('Real-time broadcast failed; polling fallback will deliver the notification', [
                'user_id' => $recipientId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * DB::afterCommit(): run the closure after the current transaction commits
     * (or immediately when no transaction is open). On rollback it is discarded.
     */
    private function afterCommit(Closure $sideEffect): void
    {
        DB::afterCommit(function () use ($sideEffect) {
            if ($this->holding) {
                $this->heldSideEffects[] = $sideEffect;

                return;
            }

            $sideEffect();
        });
    }

    public function holdSideEffects(): void
    {
        $this->holding = true;
    }

    /** Runs the side effects collected while holding (call after releasing the lock). */
    public function releaseSideEffects(): void
    {
        $this->holding = false;
        $sideEffects = $this->heldSideEffects;
        $this->heldSideEffects = [];

        foreach ($sideEffects as $sideEffect) {
            $sideEffect();
        }
    }
}
