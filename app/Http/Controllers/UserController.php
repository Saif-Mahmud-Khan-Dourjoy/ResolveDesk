<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
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
        $query = User::query();

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

        if ($request->input('status') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->input('status') === 'inactive') {
            $query->where('is_active', false);
        }

        $users = $query->orderBy('name')->paginate(self::PER_PAGE)->withQueryString();

        return view('users.index', ['users' => $users]);
    }

    public function create()
    {
        return view('users.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'is_active' => ['boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        // The 'hashed' cast in the User model hashes the password automatically.
        $user = User::create($validated);

        return redirect()->route('users.show', $user)->with('success', "User {$user->name} was created.");
    }

    public function show(User $user)
    {
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
        return view('users.edit', ['user' => $user]);
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
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        $this->ensureRoleOrStatusChangeIsAllowed($user, $validated['role'], $validated['is_active'], $request->user());

        // Empty password field = keep the current password.
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $user->update($validated);

        return redirect()->route('users.show', $user)->with('success', 'User updated successfully.');
    }

    /** Activate / deactivate button on the user list and details page. */
    public function toggleActive(Request $request, User $user)
    {
        $newIsActive = ! $user->is_active;

        $this->ensureRoleOrStatusChangeIsAllowed($user, $user->role, $newIsActive, $request->user());

        $user->is_active = $newIsActive;
        $user->save();

        $word = $newIsActive ? 'activated' : 'deactivated';

        return back()->with('success', "User {$user->name} was {$word}.");
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
        if ($user->isAdmin() && $user->is_active) {
            $otherActiveAdmins = User::where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
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
