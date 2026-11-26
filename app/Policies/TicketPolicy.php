<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

/**
 * Ticket-level authorization: "may THIS user do THIS action on THIS ticket?"
 *
 * Laravel finds this policy automatically (App\Models\Ticket -> App\Policies\TicketPolicy).
 *
 * Used in two places with the same rules:
 *   Controllers:  Gate::authorize('resolve', $ticket);   // throws 403 if false
 *   Blade views:  @can('resolve', $ticket) ... @endcan    // hides invalid buttons
 *
 * Each method checks WHO the user is and whether the ticket's current STATUS
 * allows the action (using the central Ticket::TRANSITIONS table).
 */
class TicketPolicy
{
    /** Admin sees all tickets; others only tickets they created or are assigned to. */
    public function view(User $user, Ticket $ticket): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->isCreator($user, $ticket) || $this->isAssignedAgent($user, $ticket);
    }

    /** Every active role may create tickets. */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Edit subject/description/category/priority.
     * Admin: any ticket that is not closed.
     * Creator: only while the ticket is still Open and unassigned.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        if ($ticket->isClosed()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $this->isCreator($user, $ticket)
            && $ticket->status === Ticket::STATUS_OPEN
            && $ticket->assigned_to === null;
    }

    /** Assign, auto-assign or reassign (admin only, ticket still active). */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() && in_array($ticket->status, Ticket::ACTIVE_STATUSES, true);
    }

    /** Assigned -> In Progress, by the assigned agent or an admin. */
    public function start(User $user, Ticket $ticket): bool
    {
        return ($user->isAdmin() || $this->isAssignedAgent($user, $ticket))
            && $ticket->canPerform('start');
    }

    /** In Progress -> Resolved, by the assigned agent or an admin. */
    public function resolve(User $user, Ticket $ticket): bool
    {
        return ($user->isAdmin() || $this->isAssignedAgent($user, $ticket))
            && $ticket->canPerform('resolve');
    }

    /** Resolved -> Closed, by the ticket creator or an admin. */
    public function close(User $user, Ticket $ticket): bool
    {
        return ($user->isAdmin() || $this->isCreator($user, $ticket))
            && $ticket->canPerform('close');
    }

    /** Resolved -> In Progress, by the ticket creator or an admin. */
    public function reopen(User $user, Ticket $ticket): bool
    {
        return ($user->isAdmin() || $this->isCreator($user, $ticket))
            && $ticket->canPerform('reopen');
    }

    /** Anyone who can view the ticket may comment, except on closed tickets. */
    public function comment(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket) && ! $ticket->isClosed();
    }

    // ---- Small helpers ------------------------------------------------------

    private function isCreator(User $user, Ticket $ticket): bool
    {
        return $ticket->created_by === $user->id;
    }

    private function isAssignedAgent(User $user, Ticket $ticket): bool
    {
        return $ticket->assigned_to !== null && $ticket->assigned_to === $user->id;
    }
}
