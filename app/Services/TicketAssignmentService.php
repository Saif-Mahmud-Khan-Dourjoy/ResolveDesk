<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * V2: manual assignment, reassignment and automatic (least-workload) assignment.
 *
 * Three layers of protection, in this order (consistent lock order = no deadlocks):
 *
 *   1. Cache::lock('ticket-assignment')   ATOMIC LOCK (cache_locks table)
 *      Only one assignment runs at a time, so two auto-assigns of DIFFERENT
 *      tickets cannot both count the same workload and pick the same agent.
 *      Manual assignment uses the same lock, because it also changes workload.
 *   2. Project row: SELECT ... LOCK IN SHARE MODE
 *      Blocks a concurrent "remove agent from project" (ProjectService locks the
 *      project row FOR UPDATE) until we commit, so eligibility cannot change under us.
 *   3. Ticket row: SELECT ... FOR UPDATE   PESSIMISTIC LOCK
 *      We re-read the ticket and validate status + version on the CURRENT row.
 *   4. Agent's users row: SELECT ... FOR UPDATE
 *      UserController locks the same row before deactivating/demoting a user, so
 *      "assign to X" and "deactivate X" cannot interleave (no inactive ticket owner).
 *
 * The cache lock expires after config('resolvedesk.assignment_lock.seconds'), so it is
 * a coordination tool, not a database guarantee: the ticket row lock and the
 * status/version checks remain the real protection of the ticket itself.
 */
class TicketAssignmentService
{
    public function __construct(private TicketNotifier $notifier) {}

    /**
     * Manual assignment or reassignment by an administrator.
     * Returns false when the ticket is already assigned to this agent (no duplicates).
     */
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

    /**
     * Automatic assignment to the ELIGIBLE agent with the fewest Assigned/In Progress
     * tickets (counted across all projects). Returns the chosen agent, or null when
     * no eligible agent exists (the ticket stays Open, nothing is written).
     */
    public function autoAssign(Ticket $ticket, User $admin, ?int $expectedVersion = null): ?User
    {
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

        if ($chosenAgent !== null) {
            Cache::forget(TicketService::DASHBOARD_CACHE_KEY);
        }

        return $chosenAgent;
    }

    /**
     * Least-workload algorithm.
     *
     * Equivalent SQL (project ticket; for a global ticket the EXISTS part is left out):
     *   SELECT users.*, (SELECT COUNT(*) FROM tickets
     *                    WHERE tickets.assigned_to = users.id
     *                    AND tickets.status IN ('assigned','in_progress')) AS workload_count
     *   FROM users
     *   WHERE role = 'agent' AND is_active = 1
     *     AND EXISTS (SELECT 1 FROM project_user
     *                 WHERE project_user.user_id = users.id AND project_user.project_id = ?)
     *   ORDER BY workload_count ASC, id ASC      -- id = deterministic tie-breaker
     *   LIMIT 1
     */
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

    /**
     * Agents that may be assigned this ticket, with their workload (for dropdowns).
     * The dropdown is only a convenience: performAssignment() re-checks eligibility.
     */
    public function eligibleAgents(Ticket $ticket)
    {
        return User::where('role', User::ROLE_AGENT)
            ->where('is_active', true)
            ->when($ticket->project_id !== null, function ($query) use ($ticket) {
                $query->whereHas('projects', function ($projectQuery) use ($ticket) {
                    $projectQuery->where('projects.id', $ticket->project_id);
                });
            })
            ->withCount(['assignedTickets as workload_count' => function ($query) {
                $query->whereIn('status', Ticket::WORKLOAD_STATUSES);
            }])
            ->orderBy('name')
            ->get();
    }

    // =========================================================================

    /** Shared by manual and automatic assignment. Must run inside the transaction. */
    private function performAssignment(Ticket $ticket, User $agent, User $admin): bool
    {
        if (! in_array($ticket->status, Ticket::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'ticket' => 'Only Open, Assigned or In Progress tickets can be assigned.',
            ]);
        }

        // Re-read the agent WITH a row lock: never trust that the submitted user is still an
        // eligible agent. If an admin is deactivating this agent right now, we wait for
        // that commit and then see is_active = 0 (lock order: ... ticket row -> user row).
        $agent = User::whereKey($agent->id)->lockForUpdate()->first();
        if ($agent === null || ! $ticket->canBeAssignedTo($agent)) {
            $message = $ticket->project_id === null
                ? 'Please select an active support agent.'
                : 'Please select an active support agent who supports this ticket\'s project.';
            throw ValidationException::withMessages(['agent_id' => $message]);
        }

        // Duplicate prevention: same agent => nothing to do, no version change.
        if ($ticket->assigned_to === $agent->id) {
            return false;
        }

        $previousAgent = $ticket->assigned_to ? User::find($ticket->assigned_to) : null;
        $previousStatusLabel = $ticket->statusLabel();

        $ticket->assigned_to = $agent->id;
        if ($ticket->status === Ticket::STATUS_OPEN) {
            $ticket->status = Ticket::STATUS_ASSIGNED; // Open -> Assigned happens through assignment.
        }
        // Reassignment keeps the current status (Assigned or In Progress).
        $ticket->version = $ticket->version + 1;
        $ticket->save();

        if ($previousAgent === null) {
            TicketActivity::record($ticket, $admin, 'assigned', null, $agent->name);
            TicketActivity::record($ticket, $admin, 'status_changed', $previousStatusLabel, $ticket->statusLabel());

            $this->notifier->notify($agent, $ticket, $admin, 'assigned',
                'New ticket assigned',
                "Ticket {$ticket->ticket_number} \"{$ticket->subject}\" has been assigned to you.",
                true);
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

        return true;
    }

    /**
     * Lock order: project row (shared) first, then the ticket row (exclusive).
     * The ticket's project_id never changes after creation, so reading it from the
     * route-bound model before locking is safe.
     */
    private function lockProjectAndTicket(Ticket $ticket): Ticket
    {
        if ($ticket->project_id !== null) {
            Project::whereKey($ticket->project_id)->sharedLock()->first();
        }

        return Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Runs $operation while holding the global assignment lock.
     * block(): wait up to N seconds for the lock, then throw LockTimeoutException.
     * The lock is always released when $operation finishes (also on exceptions).
     * Broadcasts/emails created inside are held until the lock is released.
     */
    private function withAssignmentLock(Closure $operation): mixed
    {
        $lock = Cache::lock(
            config('resolvedesk.assignment_lock.key'),
            config('resolvedesk.assignment_lock.seconds')
        );

        $this->notifier->holdSideEffects();

        try {
            return $lock->block(config('resolvedesk.assignment_lock.wait_seconds'), $operation);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'ticket' => 'Another assignment is being processed right now. Please try again in a moment.',
            ]);
        } finally {
            // Lock released (block() does that) -> now run the committed side effects.
            $this->notifier->releaseSideEffects();
        }
    }
}
