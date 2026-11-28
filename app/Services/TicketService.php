<?php

namespace App\Services;

use App\Exceptions\TicketConflictException;
use App\Models\Client;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ticket operations with several coordinated business rules:
 * create, edit, comments/internal notes, lifecycle transitions and SLA breaches.
 * (Assignment lives in TicketAssignmentService; notifications in TicketNotifier.)
 *
 * Every mutating method follows the same pattern:
 *   1. DB::transaction(..., 3): all changes succeed together or are rolled back;
 *      the "3" retries the transaction if MySQL reports a deadlock.
 *   2. lockTicket(): SELECT ... FOR UPDATE, so we validate the CURRENT row,
 *      not the possibly stale model from route model binding.
 *   3. ensureVersion(): optimistic check of the version the user saw (409 on mismatch).
 *   4. authorizeOnCurrentState(): TicketPolicy again, on the LOCKED row (403).
 *   5. Change the ticket, version + 1, record activity, create notifications.
 *   6. After COMMIT: emails/broadcasts (TicketNotifier) and cache invalidation.
 *
 * Business-rule failures throw ValidationException (redirect back with an error,
 * or 422 JSON). Stale data throws TicketConflictException (409).
 * WHO may do an action is checked in the controller with TicketPolicy (fast 403 on the
 * route-bound copy) AND again here on the locked row (the copy may be stale).
 */
class TicketService
{
    /** Admin dashboard statistics cache key (see DashboardController). */
    public const DASHBOARD_CACHE_KEY = 'dashboard:admin:stats';

    public function __construct(
        private TicketNotifier $notifier,
        private AttachmentService $attachmentService,
    ) {}

    // =========================================================================
    // Create (with idempotency) and edit (with optimistic locking)
    // =========================================================================

    /**
     * @param  array  $data  validated: subject, description, category_id, priority,
     *                       project_id?, client_id?, request_token
     * @param  array  $files  validated UploadedFile objects
     *
     * Returns the ticket. $ticket->wasRecentlyCreated is false when the request
     * was a REPLAY of an already successful submission (same request_token).
     */
    public function createTicket(array $data, User $creator, array $files = []): Ticket
    {
        $requestToken = $data['request_token'];

        // Replay of a request that already succeeded (double click, browser retry)?
        $existingTicket = $this->findByRequestToken($creator, $requestToken);
        if ($existingTicket !== null) {
            return $this->replayOrReject($existingTicket, $data);
        }

        // Files are written BEFORE the transaction; on any failure they are deleted again.
        $storedFiles = $this->attachmentService->storeFiles($files);

        try {
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
                // If the same creator + token was inserted a moment ago by a parallel
                // request, the UNIQUE index makes this INSERT fail (caught below).

                // Ticket number from the database id: always unique (unlike MAX(id) + 1).
                $ticket->ticket_number = Ticket::makeTicketNumber($ticket->id);
                $ticket->save();

                TicketActivity::record($ticket, $creator, 'created', null, 'Open');

                if (count($storedFiles) > 0) {
                    $this->attachmentService->createRecords($storedFiles, $ticket, null, $creator);
                    TicketActivity::record($ticket, $creator, 'attachment_added', null, $this->fileList($storedFiles));
                }

                // Tell every active administrator (except an admin who created it) - in-app + email.
                $this->notifier->notifyAdmins($ticket, $creator, 'created',
                    'New ticket created',
                    "{$creator->name} created ticket {$ticket->ticket_number} \"{$ticket->subject}\".",
                    true);

                return $ticket;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A parallel request with the SAME token committed first.
            $this->attachmentService->deleteFiles($storedFiles);
            $existingTicket = $this->findByRequestToken($creator, $requestToken);
            if ($existingTicket === null) {
                throw $exception; // some other unique index failed
            }

            return $this->replayOrReject($existingTicket, $data);
        } catch (Throwable $exception) {
            $this->attachmentService->deleteFiles($storedFiles); // no orphaned files
            throw $exception;
        }

        $this->clearDashboardCache();

        return $ticket;
    }

    /**
     * Edit subject, description, category and priority (never status, scope or owner).
     *
     * OPTIMISTIC LOCKING: one atomic conditional UPDATE
     *   UPDATE tickets SET ..., version = version + 1
     *   WHERE id = ? AND version = ? AND status <> 'closed'
     * If another user changed the ticket since the form was opened, the version
     * no longer matches, 0 rows are updated and we throw a 409 conflict instead
     * of silently overwriting their change.
     *
     * Returns false when nothing actually changed (no activity is written).
     */
    public function updateTicket(Ticket $ticket, array $data, int $expectedVersion, User $editor): bool
    {
        $ticket->fill($data);

        if (! $ticket->isDirty()) {
            return false;
        }

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

        if ($priorityChanged) {
            // SLA is always recalculated from the ORIGINAL creation time, and a new
            // deadline gets a fresh SLA-breach reminder (sla_notified_at reset).
            $ticket->due_at = Ticket::calculateDueAt($ticket->created_at, $ticket->priority);
            $ticket->sla_notified_at = null;
        }

        DB::transaction(function () use ($ticket, $expectedVersion, $editor, $changedFields, $priorityChanged, $oldPriority, $oldDueAt) {
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

            $ticket->version = $expectedVersion + 1;
            $ticket->syncOriginal(); // the model now matches the database row

            if (count($changedFields) > 0) {
                TicketActivity::record($ticket, $editor, 'updated', null, 'Changed: ' . implode(', ', $changedFields));
            }

            if ($priorityChanged) {
                TicketActivity::record($ticket, $editor, 'priority_changed', Ticket::PRIORITIES[$oldPriority], $ticket->priorityLabel());
                TicketActivity::record($ticket, $editor, 'due_date_changed', $this->formatDate($oldDueAt), $this->formatDate($ticket->due_at));
            }
        }, 3);

        $this->clearDashboardCache();

        return true;
    }

    // =========================================================================
    // Status transitions (row lock + version check)
    // =========================================================================

    /** Assigned -> In Progress. */
    public function startWork(Ticket $ticket, User $user, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($ticket, $user, $expectedVersion) {
            $lockedTicket = $this->lockTicket($ticket);
            $lockedTicket->ensureVersion($expectedVersion);
            $this->ensureActionAllowed($lockedTicket, 'start');
            $this->authorizeOnCurrentState($user, 'start', $lockedTicket);

            $lockedTicket->status = Ticket::STATUS_IN_PROGRESS;
            $this->saveNewVersion($lockedTicket);

            TicketActivity::record($lockedTicket, $user, 'status_changed', 'Assigned', 'In Progress');
        }, 3);

        $this->clearDashboardCache();
    }

    /** In Progress -> Resolved. A resolution note is required. */
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
            $this->authorizeOnCurrentState($user, 'resolve', $lockedTicket);

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

    /**
     * Resolved -> In Progress.
     * The original SLA deadline is kept on purpose (documented rule): if it has
     * already passed, the reopened ticket is immediately overdue. sla_notified_at
     * is reset so the new overdue period produces ONE fresh SLA reminder.
     */
    public function reopen(Ticket $ticket, User $user, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($ticket, $user, $expectedVersion) {
            $lockedTicket = $this->lockTicket($ticket);
            $lockedTicket->ensureVersion($expectedVersion);
            $this->ensureActionAllowed($lockedTicket, 'reopen');
            $this->authorizeOnCurrentState($user, 'reopen', $lockedTicket);

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

            $this->notifier->notify($agent, $lockedTicket, $user, 'reopened',
                'Ticket reopened',
                "Ticket {$lockedTicket->ticket_number} was reopened and needs more work.",
                true);
        }, 3);

        $this->clearDashboardCache();
    }

    /** Resolved -> Closed. resolved_at is kept. Closed tickets are read-only. */
    public function close(Ticket $ticket, User $user, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($ticket, $user, $expectedVersion) {
            $lockedTicket = $this->lockTicket($ticket);
            $lockedTicket->ensureVersion($expectedVersion);
            $this->ensureActionAllowed($lockedTicket, 'close');
            $this->authorizeOnCurrentState($user, 'close', $lockedTicket);

            $lockedTicket->status = Ticket::STATUS_CLOSED;
            $this->saveNewVersion($lockedTicket);

            TicketActivity::record($lockedTicket, $user, 'closed', 'Resolved', 'Closed');

            // In-app only (no email) for closure.
            $this->notifier->notify($lockedTicket->assignedAgent, $lockedTicket, $user, 'closed',
                'Ticket closed',
                "Ticket {$lockedTicket->ticket_number} was confirmed and closed.",
                false);
        }, 3);

        $this->clearDashboardCache();
    }

    // =========================================================================
    // Comments and internal notes
    // =========================================================================

    /**
     * Public reply: notifies the ticket creator and assigned agent (except the author).
     *   - customer (creator) reply -> assigned agent: in-app + EMAIL
     *   - agent/admin reply        -> creator (and agent): in-app only
     *
     * Internal note (staff only): notifies only STAFF participants, never the
     * customer, never by email. Its attachments inherit the internal visibility.
     */
    public function addComment(Ticket $ticket, string $message, string $visibility, User $author, array $files = []): TicketComment
    {
        if ($visibility === TicketComment::VISIBILITY_INTERNAL && ! $author->isStaff()) {
            // The policy already blocks this; this is a second, defensive check.
            throw ValidationException::withMessages(['visibility' => 'Only staff can add internal notes.']);
        }

        $storedFiles = $this->attachmentService->storeFiles($files);

        try {
            return DB::transaction(function () use ($ticket, $message, $visibility, $author, $storedFiles) {
                $lockedTicket = $this->lockTicket($ticket);

                if ($lockedTicket->isClosed()) {
                    throw ValidationException::withMessages([
                        'message' => 'Closed tickets are read-only. Comments are not allowed.',
                    ]);
                }

                $isInternal = $visibility === TicketComment::VISIBILITY_INTERNAL;
                $this->authorizeOnCurrentState($author, $isInternal ? 'addInternalNote' : 'comment', $lockedTicket);

                $comment = new TicketComment;
                $comment->message = $message;
                $comment->visibility = $visibility;
                $comment->ticket_id = $lockedTicket->id;
                $comment->user_id = $author->id;
                $comment->save();

                TicketActivity::record($lockedTicket, $author, $isInternal ? 'internal_note_added' : 'comment_added',
                    null, null, $isInternal);

                if (count($storedFiles) > 0) {
                    $this->attachmentService->createRecords($storedFiles, $lockedTicket, $comment, $author);
                    TicketActivity::record($lockedTicket, $author, 'attachment_added', null, $this->fileList($storedFiles), $isInternal);
                }

                $this->notifyCommentRecipients($lockedTicket, $author, $message, $isInternal);

                return $comment;
            }, 3);
        } catch (Throwable $exception) {
            $this->attachmentService->deleteFiles($storedFiles);
            throw $exception;
        }
    }

    // =========================================================================
    // SLA breach (called by the tickets:check-sla scheduled command)
    // =========================================================================

    /**
     * Sends ONE SLA-breach reminder for an overdue ticket.
     * Returns true when a reminder was sent, false when nothing had to be done.
     *
     * Deduplication: the check "overdue AND sla_notified_at IS NULL" and the
     * update "sla_notified_at = NOW()" happen on the LOCKED row in one
     * transaction, so two scheduler runs (or servers) cannot both send it.
     */
    public function processSlaBreach(Ticket $ticket): bool
    {
        $wasNotified = DB::transaction(function () use ($ticket) {
            $lockedTicket = $this->lockTicket($ticket);

            if (! $lockedTicket->isOverdue() || $lockedTicket->sla_notified_at !== null) {
                return false; // resolved meanwhile, deadline changed, or already notified
            }

            // NO version + 1 here: sla_notified_at is internal bookkeeping, not a change a
            // user made. Bumping the version would give every open page/edit form of this
            // ticket a false "changed by someone else" conflict. (A concurrent edit cannot
            // lose this value: the edit UPDATE only writes the columns the user changed.)
            $lockedTicket->sla_notified_at = now();
            $lockedTicket->save();

            // user = null: performed by the SYSTEM, not by a person.
            TicketActivity::record($lockedTicket, null, 'sla_breached', null,
                'Deadline was ' . $this->formatDate($lockedTicket->due_at));

            $title = 'SLA deadline breached';
            $message = "Ticket {$lockedTicket->ticket_number} passed its SLA deadline ({$this->formatDate($lockedTicket->due_at)}).";

            $agent = $lockedTicket->assigned_to ? User::find($lockedTicket->assigned_to) : null;
            if ($agent !== null && $agent->is_active) {
                $this->notifier->notify($agent, $lockedTicket, null, 'sla_breached', $title, $message, true);
            } else {
                // Unassigned (or agent inactive): the administrators must act.
                $this->notifier->notifyAdmins($lockedTicket, null, 'sla_breached', $title, $message, true);
            }

            return true;
        }, 3);

        if ($wasNotified) {
            $this->clearDashboardCache();
        }

        return $wasNotified;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Decides project_id and client_id for a NEW ticket from the creator's role.
     * Runs inside the creation transaction. Nothing submitted is trusted:
     *
     *   Customer: client_id = the customer's own organization (input ignored).
     *             Project must be active AND used by that organization.
     *             Standalone customers (no organization): global tickets only.
     *   Agent:    internal tickets only (client_id = NULL). Project must be one
     *             the agent supports.
     *   Admin:    any combination; client must be active; if both are chosen,
     *             the client must use the project.
     *
     * The project row is read with a SHARED lock, so a concurrent removal of the
     * client/agent association (ProjectService, FOR UPDATE) waits until we commit.
     *
     * @return array{0: ?int, 1: ?int} [project_id, client_id]
     */
    private function resolveScope(User $creator, $projectId, $clientId): array
    {
        $projectId = $projectId ? (int) $projectId : null;
        $clientId = $clientId ? (int) $clientId : null;

        // The project row lock is taken FIRST, before any normal SELECT in this transaction.
        // Why: in MySQL (REPEATABLE READ) the first plain SELECT fixes the "snapshot" the
        // transaction reads from. Locking first means that if we had to wait for a
        // concurrent association change, our later checks see its committed result.
        $project = $projectId !== null ? Project::whereKey($projectId)->sharedLock()->first() : null;

        if ($creator->isCustomer()) {
            $clientId = $creator->client_id;

            if ($clientId !== null && ! Client::whereKey($clientId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'project_id' => 'Your organization is inactive, so new tickets cannot be created.',
                ]);
            }

            if ($projectId !== null && $clientId === null) {
                throw ValidationException::withMessages([
                    'project_id' => 'Your account is not linked to a client organization, so you can only create global tickets.',
                ]);
            }
        } elseif ($creator->isAgent()) {
            $clientId = null;
        } elseif ($clientId !== null && ! Client::whereKey($clientId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['client_id' => 'Please choose an active client organization.']);
        }

        if ($projectId === null) {
            return [null, $clientId];
        }

        if ($project === null || ! $project->is_active) {
            throw ValidationException::withMessages(['project_id' => 'Please choose an active project.']);
        }

        if ($creator->isAgent() && ! $project->agents()->where('users.id', $creator->id)->exists()) {
            throw ValidationException::withMessages(['project_id' => 'You can only create tickets for projects you support.']);
        }

        if ($clientId !== null && ! $project->clients()->where('clients.id', $clientId)->exists()) {
            throw ValidationException::withMessages(['project_id' => 'The selected project is not used by this client organization.']);
        }

        return [$projectId, $clientId];
    }

    private function findByRequestToken(User $creator, string $requestToken): ?Ticket
    {
        return Ticket::where('created_by', $creator->id)->where('request_token', $requestToken)->first();
    }

    /**
     * Idempotency policy:
     *   (content = subject, description, category, priority and project; the client is
     *   derived from the account for customers, so it is not compared)
     *   same token + same content      -> return the existing ticket (no duplicate
     *                                     ticket, activity, attachment or notification)
     *   same token + different content -> 409 conflict (the token was reused wrongly)
     */
    private function replayOrReject(Ticket $existingTicket, array $data): Ticket
    {
        $sameContent = $existingTicket->subject === $data['subject']
            && $existingTicket->description === $data['description']
            && $existingTicket->category_id === (int) $data['category_id']
            && $existingTicket->priority === $data['priority']
            && (int) $existingTicket->project_id === (int) ($data['project_id'] ?? 0);

        if (! $sameContent) {
            throw new TicketConflictException(
                'This form was already submitted with different content. Please open a new ticket form and try again.'
            );
        }

        return $existingTicket; // wasRecentlyCreated = false -> controller shows "already submitted"
    }

    private function notifyCommentRecipients(Ticket $ticket, User $author, string $message, bool $isInternal): void
    {
        $creator = $ticket->creator;
        $agent = $ticket->assignedAgent;
        $snippet = mb_strimwidth($message, 0, 80, '...');

        if ($isInternal) {
            // Staff participants only. A customer creator is never told about internal notes.
            $recipients = [$agent];
            if ($creator->isStaff()) {
                $recipients[] = $creator;
            }
            $title = 'Internal note from ' . $author->name;
            $text = "Internal note on ticket {$ticket->ticket_number}: {$snippet}";
        } else {
            $recipients = [$creator, $agent];
            $title = 'New comment from ' . $author->name;
            $text = "New comment on ticket {$ticket->ticket_number}: {$snippet}";
        }

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
    }

    /** Re-reads the ticket with a row lock: SELECT * FROM tickets WHERE id = ? FOR UPDATE */
    private function lockTicket(Ticket $ticket): Ticket
    {
        return Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Runs the TicketPolicy rule again on the LOCKED, current row.
     *
     * Why: the controller authorized the route-bound copy, which was read BEFORE the
     * lock. If an admin reassigned the ticket in between, the old agent would still
     * pass that first check. (A form sends "version" and would get a 409, but a
     * hand-made request without "version" would not.) Throws AuthorizationException (403).
     */
    private function authorizeOnCurrentState(User $user, string $ability, Ticket $lockedTicket): void
    {
        Gate::forUser($user)->authorize($ability, $lockedTicket);
    }

    /** Every ticket mutation increments the optimistic-locking version. */
    private function saveNewVersion(Ticket $ticket): void
    {
        $ticket->version = $ticket->version + 1;
        $ticket->save();
    }

    private function ensureActionAllowed(Ticket $ticket, string $action): void
    {
        if (! $ticket->canPerform($action)) {
            throw ValidationException::withMessages([
                'ticket' => "This action is not allowed while the ticket is {$ticket->statusLabel()}.",
            ]);
        }
    }

    public function clearDashboardCache(): void
    {
        Cache::forget(self::DASHBOARD_CACHE_KEY);
    }

    private function fileList(array $storedFiles): string
    {
        return implode(', ', array_column($storedFiles, 'original_name'));
    }

    private function formatDate($date): string
    {
        return $date ? $date->format('d M Y, h:i A') : '-';
    }
}
