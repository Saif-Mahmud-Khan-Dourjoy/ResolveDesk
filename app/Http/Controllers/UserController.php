<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * User management (administrators only - see the role:admin route group).
 * Users are never deleted because tickets, comments and activities reference them.
 */
class UserController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(array_keys(User::ROLES))],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'client_id' => ['nullable', 'integer'],
        ]);

        $query = User::with('client');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->input('client_id'));
        }

        if ($request->input('status') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->input('status') === 'inactive') {
            $query->where('is_active', false);
        }

        $users = $query->orderBy('name')->paginate(self::PER_PAGE)->withQueryString();

        return view('users.index', ['users' => $users, 'clients' => $this->clientList()]);
    }

    public function create()
    {
        return view('users.create', ['clients' => $this->clientList()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'is_active' => ['boolean'],
            // V2: only customers belong to a client organization; a NEW link needs an active client.
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
        ], [
            'client_id.exists' => 'Please choose an active client organization.',
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $clientId = $validated['role'] === User::ROLE_CUSTOMER ? ($validated['client_id'] ?? null) : null;
        unset($validated['client_id']);

        // The 'hashed' cast in the User model hashes the password automatically.
        $user = new User($validated);
        $user->client_id = $clientId; // not mass assignable: set explicitly
        $user->save();
        Cache::forget(TicketService::DASHBOARD_CACHE_KEY); // "active agents" count on the admin dashboard

        return redirect()->route('users.show', $user)->with('success', "User {$user->name} was created.");
    }

    public function show(User $user)
    {
        $user->load(['client', 'projects']);

        $createdCount = $user->createdTickets()->count();
        $assignedActiveCount = $user->assignedTickets()->whereIn('status', Ticket::WORKLOAD_STATUSES)->count();

        $recentTickets = Ticket::with(['category', 'assignedAgent', 'creator'])
            ->where(function ($query) use ($user) {
                $query->where('created_by', $user->id)
                    ->orWhere('assigned_to', $user->id);
            })
            ->latest()
            ->take(10)
            ->get();

        return view('users.show', [
            'user' => $user,
            'createdCount' => $createdCount,
            'assignedActiveCount' => $assignedActiveCount,
            'recentTickets' => $recentTickets,
        ]);
    }

    public function edit(User $user)
    {
        return view('users.edit', ['user' => $user, 'clients' => $this->clientList($user->client_id)]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Unique, but ignore this user's own current email.
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'], // optional when editing
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'is_active' => ['boolean'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $newClientId = $validated['role'] === User::ROLE_CUSTOMER && ! empty($validated['client_id'])
            ? (int) $validated['client_id']
            : null;
        unset($validated['client_id']);

        // Empty password field = keep the current password.
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        // Check-then-save under a row lock (see lockUser()): the rules below count the
        // user's tickets, and a parallel assignment must not slip in between.
        DB::transaction(function () use ($user, $validated, $newClientId, $request) {
            $lockedUser = $this->lockUser($user);

            $this->ensureRoleOrStatusChangeIsAllowed($lockedUser, $validated['role'], $validated['is_active'], $request->user());
            $this->ensureClientChangeIsAllowed($lockedUser, $newClientId);

            $lockedUser->client_id = $newClientId;
            $lockedUser->update($validated);
        }, 3);
        Cache::forget(TicketService::DASHBOARD_CACHE_KEY);

        return redirect()->route('users.show', $user)->with('success', 'User updated successfully.');
    }

    /** Activate / deactivate button on the user list and details page. */
    public function toggleActive(Request $request, User $user)
    {
        $newIsActive = DB::transaction(function () use ($user, $request) {
            $lockedUser = $this->lockUser($user);
            $newIsActive = ! $lockedUser->is_active;

            $this->ensureRoleOrStatusChangeIsAllowed($lockedUser, $lockedUser->role, $newIsActive, $request->user());

            $lockedUser->is_active = $newIsActive;
            $lockedUser->save();

            return $newIsActive;
        }, 3);
        Cache::forget(TicketService::DASHBOARD_CACHE_KEY);

        $word = $newIsActive ? 'activated' : 'deactivated';

        return back()->with('success', "User {$user->name} was {$word}.");
    }

    /**
     * SELECT * FROM users WHERE id = ? FOR UPDATE  (inside the caller's transaction)
     *
     * Why: "deactivate agent X" checks "X has no active tickets" and then saves. Without
     * a lock, an assignment to X that commits between the check and the save would leave
     * an INACTIVE agent owning a ticket. TicketAssignmentService locks the same row, so
     * the two operations now run one after the other. Because this lock is the FIRST
     * statement of the transaction, the ticket count afterwards sees the other commit
     * (MySQL REPEATABLE READ fixes its snapshot at the first normal SELECT).
     */
    private function lockUser(User $user): User
    {
        return User::whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * V2 rules for changing a customer's client organization.
     *
     * - A NEW client must be active (keeping the current, now inactive, client is allowed).
     * - If the customer already created client-related tickets, the organization cannot
     *   change: those tickets belong to the old client, and moving the user would let
     *   them keep seeing another organization's tickets (cross-client leakage).
     *   Ticket ownership is never transferred silently.
     */
    private function ensureClientChangeIsAllowed(User $user, ?int $newClientId): void
    {
        if ($user->client_id === $newClientId) {
            return;
        }

        if ($newClientId !== null && ! Client::whereKey($newClientId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'client_id' => 'Please choose an active client organization.',
            ]);
        }

        $clientTicketCount = Ticket::where('created_by', $user->id)->whereNotNull('client_id')->count();

        if ($clientTicketCount > 0) {
            throw ValidationException::withMessages([
                'client_id' => "This customer already created {$clientTicketCount} ticket(s) for their organization, "
                    . 'so the organization (and the customer role) cannot be changed. Create a new user account instead.',
            ]);
        }
    }

    /** Active clients for the dropdown, plus the user's current client even if it is inactive. */
    private function clientList(?int $currentClientId = null)
    {
        return Client::where('is_active', true)
            ->when($currentClientId, fn ($query) => $query->orWhere('id', $currentClientId))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_active']);
    }

    /**
     * Business rules for deactivating or changing the role of a user.
     * Throws a ValidationException (shown as an error message) if not allowed.
     */
    private function ensureRoleOrStatusChangeIsAllowed(User $user, string $newRole, bool $newIsActive, User $currentAdmin): void
    {
        $isDeactivating = $user->is_active && ! $newIsActive;
        $isChangingRole = $user->role !== $newRole;

        if (! $isDeactivating && ! $isChangingRole) {
            return;
        }

        // Rule 1: an administrator cannot deactivate or demote themselves.
        if ($user->id === $currentAdmin->id) {
            throw ValidationException::withMessages([
                'is_active' => 'You cannot deactivate your own account or change your own role.',
            ]);
        }

        // Rule 2: always keep at least one active administrator.
        // The other admin rows are locked too: if two admins deactivate EACH OTHER at the
        // same moment, MySQL reports a deadlock, DB::transaction(..., 3) retries the loser,
        // and the retry sees the first change and refuses (never 0 active admins).
        if ($user->isAdmin() && $user->is_active) {
            $otherActiveAdmins = User::where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->lockForUpdate()
                ->count();

            if ($otherActiveAdmins === 0) {
                throw ValidationException::withMessages([
                    'is_active' => 'The last active administrator cannot be deactivated or demoted.',
                ]);
            }
        }

        // Rule 3: an agent responsible for unfinished or reopenable tickets must hand them over first.
        if ($user->isAgent()) {
            $responsibleTickets = Ticket::where('assigned_to', $user->id)
                ->whereIn('status', [Ticket::STATUS_ASSIGNED, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_RESOLVED])
                ->count();

            if ($responsibleTickets > 0) {
                throw ValidationException::withMessages([
                    'is_active' => "This agent is still responsible for {$responsibleTickets} unresolved or reopenable ticket(s). "
                        . 'Reassign their Assigned/In Progress tickets and close their Resolved tickets first.',
                ]);
            }
        }
    }
}
