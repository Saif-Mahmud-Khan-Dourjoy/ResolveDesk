<?php

namespace App\Services;

use App\Jobs\SendTicketEmail;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketComment;
use App\Models\User;
use App\Notifications\TicketNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ticket operations that involve several coordinated business rules:
 * update ticket + write activity + send in-app notification + queue email + clear cache.
 *
 * Every method follows the same pattern:
 *   1. DB::transaction(): all database changes succeed together or are all rolled back.
 *   2. lockTicket(): SELECT ... FOR UPDATE so two people cannot change the same ticket at once.
 *   3. Re-check the business rule on the freshly locked row.
 *   4. Save, record activity, create database notification (same transaction).
 *   5. Emails are queued only AFTER the transaction commits (queueEmail()).
 *   6. Clear the admin dashboard cache after a successful commit.
 *
 * Business rule failures throw ValidationException. Laravel turns that into a
 * redirect back with an error message (or a 422 JSON response for AJAX).
 *
 * Permission checks (WHO may do it) are done earlier in the controller with
 * TicketPolicy. This class assumes the user is already authorized.
 */
class TicketService
{
    /** Admin dashboard statistics cache key (see DashboardController). */
    public const DASHBOARD_CACHE_KEY = 'dashboard:admin:stats';

    // =========================================================================
    // Create and edit
    // =========================================================================

    /**
     * @param  array  $data  validated: subject, description, category_id, priority
     */
    public function createTicket(array $data, User $creator): Ticket
    {
        $ticket = DB::transaction(function () use ($data, $creator) {
            $createdAt = now();

            $ticket = new Ticket();
            $ticket->fill($data);
            $ticket->created_by = $creator->id;           // From the logged-in user, never from the form.
            $ticket->assigned_to = null;
            $ticket->status = Ticket::STATUS_OPEN;
            $ticket->created_at = $createdAt;
            $ticket->due_at = Ticket::calculateDueAt($createdAt, $ticket->priority);
            $ticket->save();                               // INSERT -> the database generates the id.

            // Ticket number is built from the database id, so it is always unique
            // (unlike MAX(id) + 1, which two requests could calculate at the same time).
            $ticket->ticket_number = Ticket::makeTicketNumber($ticket->id);
            $ticket->save();                               // UPDATE

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

    /**
     * Edit subject, description, category and priority. Status is never changed here.
     * Returns false when nothing actually changed (no activity is written).
     */
    public function updateTicket(Ticket $ticket, array $data, User $editor): bool
    {
        $ticket->fill($data);

        if (! $ticket->isDirty()) {
            return false;
        }

        // Remember old values before saving, for the activity history.
        $oldPriority = $ticket->getOriginal('priority');
        $oldDueAt = $ticket->due_at;
        $priorityChanged = $ticket->isDirty('priority');

        $changedFields = [];
        if ($ticket->isDirty('subject')) {
            $changedFields[] = 'Subject';
        }
        if ($ticket->isDirty('description')) {
            $changedFields[] = 'Description';
        }
        if ($ticket->isDirty('category_id')) {
            $changedFields[] = 'Category';
        }

        DB::transaction(function () use ($ticket, $editor, $changedFields, $priorityChanged, $oldPriority, $oldDueAt) {
            if ($priorityChanged) {
                // SLA is always recalculated from the ORIGINAL creation time.
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

        return true;
    }

    // =========================================================================
    // Assignment
    // =========================================================================

    /**
     * Manual assignment or reassignment by an administrator.
     * Returns false when the ticket is already assigned to this agent (no duplicates).
     */
    public function assignAgent(Ticket $ticket, User $agent, User $admin): bool
    {
        $wasChanged = DB::transaction(function () use ($ticket, $agent, $admin) {
            $lockedTicket = $this->lockTicket($ticket);

            return $this->performAssignment($lockedTicket, $agent, $admin);
        });

        if ($wasChanged) {
            $this->clearDashboardCache();
        }

        return $wasChanged;
    }

    /**
     * Automatic assignment to the active agent with the fewest Assigned/In Progress tickets.
     * Returns the chosen agent, or null when no active agent exists (ticket stays Open).
     */
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

    /**
     * Least-workload algorithm.
     *
     * Equivalent SQL:
     *   SELECT users.*, (SELECT COUNT(*) FROM tickets
     *                    WHERE tickets.assigned_to = users.id
     *                    AND tickets.status IN ('assigned','in_progress')) AS workload_count
     *   FROM users
     *   WHERE role = 'agent' AND is_active = 1
     *   ORDER BY workload_count ASC, id ASC      -- id = deterministic tie-breaker
     *   LIMIT 1
     */
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

    /**
     * Shared by manual and automatic assignment. Must run inside a transaction.
     */
    private function performAssignment(Ticket $ticket, User $agent, User $admin): bool
    {
        if (! in_array($ticket->status, Ticket::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'ticket' => 'Only Open, Assigned or In Progress tickets can be assigned.',
            ]);
        }

        // Re-read the agent from the database: never trust that the selected user is still an active agent.
        $agent = User::find($agent->id);
        if ($agent === null || ! $agent->isAgent() || ! $agent->is_active) {
            throw ValidationException::withMessages([
                'agent_id' => 'Please select an active support agent.',
            ]);
        }

        // Duplicate prevention: same agent => nothing to do.
        if ($ticket->assigned_to === $agent->id) {
            return false;
        }

        $previousAgent = $ticket->assignedAgent; // null if the ticket was unassigned
        $previousStatusLabel = $ticket->statusLabel();

        $ticket->assigned_to = $agent->id;
        if ($ticket->status === Ticket::STATUS_OPEN) {
            $ticket->status = Ticket::STATUS_ASSIGNED; // Open -> Assigned happens through assignment.
        }
        // Reassignment keeps the current status (Assigned or In Progress).
        $ticket->save();
        $ticket->setRelation('assignedAgent', $agent);

        if ($previousAgent === null) {
            $this->recordActivity($ticket, $admin, 'assigned', null, $agent->name);
            $this->recordActivity($ticket, $admin, 'status_changed', $previousStatusLabel, $ticket->statusLabel());

            $this->notifyUser($agent, $ticket, $admin, 'assigned',
                'New ticket assigned',
                "Ticket {$ticket->ticket_number} \"{$ticket->subject}\" has been assigned to you.",
                true);
        } else {
            $this->recordActivity($ticket, $admin, 'reassigned', $previousAgent->name, $agent->name);

            $this->notifyUser($agent, $ticket, $admin, 'reassigned',
                'Ticket reassigned to you',
                "Ticket {$ticket->ticket_number} \"{$ticket->subject}\" has been reassigned to you.",
                true);
        }

        return true;
    }

    // =========================================================================
    // Status transitions
    // =========================================================================

    /** Assigned -> In Progress. */
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

    /** In Progress -> Resolved. A resolution note is required. */
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

    /**
     * Resolved -> In Progress.
     * The original SLA deadline is kept on purpose (simplified rule): if it
     * has already passed, the reopened ticket is immediately overdue.
     */
    public function reopen(Ticket $ticket, User $user): void
    {
        DB::transaction(function () use ($ticket, $user) {
            $lockedTicket = $this->lockTicket($ticket);
            $this->ensureActionAllowed($lockedTicket, 'reopen');

            $agent = $lockedTicket->assignedAgent;
            if ($agent === null || ! $agent->is_active) {
                throw ValidationException::withMessages([
                    'ticket' => 'This ticket cannot be reopened because its assigned agent is not active. Please contact an administrator.',
                ]);
            }

            // Keep the old note in the activity history before clearing it.
            $previousNote = $lockedTicket->resolution_note;

            $lockedTicket->status = Ticket::STATUS_IN_PROGRESS;
            $lockedTicket->resolved_at = null;
            $lockedTicket->resolution_note = null;
            $lockedTicket->save();

            $this->recordActivity($lockedTicket, $user, 'reopened', 'Previous resolution: ' . $previousNote, 'In Progress');

            $this->notifyUser($agent, $lockedTicket, $user, 'reopened',
                'Ticket reopened',
                "Ticket {$lockedTicket->ticket_number} was reopened and needs more work.",
                true);
        });

        $this->clearDashboardCache();
    }

    /** Resolved -> Closed. resolved_at is kept. Closed tickets are read-only. */
    public function close(Ticket $ticket, User $user): void
    {
        DB::transaction(function () use ($ticket, $user) {
            $lockedTicket = $this->lockTicket($ticket);
            $this->ensureActionAllowed($lockedTicket, 'close');

            $lockedTicket->status = Ticket::STATUS_CLOSED;
            $lockedTicket->save();

            $this->recordActivity($lockedTicket, $user, 'closed', 'Resolved', 'Closed');

            // In-app only (no email) for closure.
            $this->notifyUser($lockedTicket->assignedAgent, $lockedTicket, $user, 'closed',
                'Ticket closed',
                "Ticket {$lockedTicket->ticket_number} was confirmed and closed.",
                false);
        });

        $this->clearDashboardCache();
    }

    // =========================================================================
    // Comments
    // =========================================================================

    /**
     * Adds a comment and notifies the other participants (in-app only, no email).
     * Recipients: ticket creator and assigned agent, except the comment author.
     * This one rule covers: creator comment -> agent, agent reply -> creator,
     * admin comment -> both.
     */
    public function addComment(Ticket $ticket, string $message, User $author): TicketComment
    {
        return DB::transaction(function () use ($ticket, $message, $author) {
            $lockedTicket = $this->lockTicket($ticket);

            if ($lockedTicket->isClosed()) {
                throw ValidationException::withMessages([
                    'message' => 'Closed tickets are read-only. Comments are not allowed.',
                ]);
            }

            $comment = new TicketComment();
            $comment->message = $message;
            $comment->ticket_id = $lockedTicket->id;
            $comment->user_id = $author->id;
            $comment->save();

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

            return $comment;
        });
    }

    // =========================================================================
    // Small shared helpers
    // =========================================================================

    /**
     * Re-reads the ticket with a row lock: SELECT * FROM tickets WHERE id = ? FOR UPDATE.
     * Another transaction trying to lock the same ticket waits until we commit.
     */
    private function lockTicket(Ticket $ticket): Ticket
    {
        return Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
    }

    private function ensureActionAllowed(Ticket $ticket, string $action): void
    {
        if (! $ticket->canPerform($action)) {
            throw ValidationException::withMessages([
                'ticket' => "This action is not allowed while the ticket is {$ticket->statusLabel()}.",
            ]);
        }
    }

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

    /**
     * Creates an in-app notification (and optionally a queued email) for one recipient.
     * Skips missing users, inactive users and the person who performed the action.
     */
    private function notifyUser(?User $recipient, Ticket $ticket, User $actor, string $eventType, string $title, string $message, bool $sendEmail): void
    {
        if ($recipient === null || ! $recipient->is_active) {
            return;
        }

        if ($recipient->id === $actor->id) {
            return; // Do not notify people about their own actions.
        }

        // Database notification: inserted inside the current transaction.
        $recipient->notify(new TicketNotification($ticket, $eventType, $title, $message));

        if ($sendEmail) {
            $this->queueEmail($recipient, $ticket, $title, $message);
        }
    }

    /**
     * Pushes the email job to the queue ONLY after the transaction commits.
     *
     * DB::afterCommit() keeps the closure until COMMIT. If the transaction
     * rolls back, the closure is thrown away and no email job is created.
     *
     * If pushing the job fails (for example the jobs table is unavailable),
     * the ticket change is already committed, so we only log the problem
     * instead of showing the user a misleading error.
     */
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

    public function clearDashboardCache(): void
    {
        Cache::forget(self::DASHBOARD_CACHE_KEY);
    }

    private function formatDate($date): string
    {
        return $date ? $date->format('d M Y, h:i A') : '-';
    }
}
