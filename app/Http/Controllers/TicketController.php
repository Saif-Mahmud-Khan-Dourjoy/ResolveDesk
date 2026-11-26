<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ticket pages (list, create, show, edit) and workflow buttons
 * (assign, auto assign, start, resolve, reopen, close).
 *
 * Flow of every workflow action:
 *   route -> middleware (auth, active[, role:admin]) -> this controller
 *   -> Gate::authorize() (TicketPolicy) -> validation -> TicketService -> redirect with message
 */
class TicketController extends Controller
{
    /** Tickets per page in the list. */
    private const PER_PAGE = 15;

    /**
     * Laravel's service container creates TicketService automatically and
     * passes it in here (constructor dependency injection).
     */
    public function __construct(private TicketService $ticketService)
    {
    }

    // =========================================================================
    // List with search and filters
    // =========================================================================

    public function index(Request $request)
    {
        $user = $request->user();

        // Filters come from the URL, so they are validated like any other input.
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(Ticket::STATUSES))],
            'priority' => ['nullable', Rule::in(array_keys(Ticket::PRIORITIES))],
            'category_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        // with(): eager loading. Loads categories, creators and agents in 3 extra
        // queries in total instead of 3 queries PER ROW (avoids the N+1 problem).
        $query = Ticket::with(['category', 'creator', 'assignedAgent'])
            ->visibleTo($user);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        // Assigned agent filter is only offered to admins.
        if ($user->isAdmin() && $request->filled('assigned_to')) {
            if ($request->input('assigned_to') === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', $request->input('assigned_to'));
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        // withQueryString(): keeps the filters in the pagination links.
        $tickets = $query->latest()->paginate(self::PER_PAGE)->withQueryString();

        return view('tickets.index', [
            'tickets' => $tickets,
            'categories' => Category::orderBy('name')->get(['id', 'name']), // include inactive for filtering old tickets
            'agents' => $user->isAdmin() ? $this->agentList() : collect(),
        ]);
    }

    // =========================================================================
    // Create
    // =========================================================================

    public function create()
    {
        Gate::authorize('create', Ticket::class);

        return view('tickets.create', [
            'categories' => Category::activeForDropdown(),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Ticket::class);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // Checked against the database (not the cache): the category must exist AND be active.
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'priority' => ['required', Rule::in(array_keys(Ticket::PRIORITIES))],
        ], [
            'category_id.exists' => 'Please choose an active category.',
        ]);

        $ticket = $this->ticketService->createTicket($validated, $request->user());

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->ticket_number} was created successfully.");
    }

    // =========================================================================
    // Show
    // =========================================================================

    public function show(Request $request, Ticket $ticket)
    {
        Gate::authorize('view', $ticket);

        // Eager load everything the page displays (one query per relationship).
        $ticket->load(['category', 'creator', 'assignedAgent', 'comments.user', 'activities.user']);

        return view('tickets.show', [
            'ticket' => $ticket,
            'agents' => $request->user()->isAdmin() ? $this->agentList() : collect(),
        ]);
    }

    // =========================================================================
    // Edit (subject, description, category, priority - never status)
    // =========================================================================

    public function edit(Ticket $ticket)
    {
        Gate::authorize('update', $ticket);

        $categories = Category::activeForDropdown();

        // Keep the ticket's current category selectable even if it was deactivated,
        // so editing does not force a category change.
        if (! $categories->contains('id', $ticket->category_id)) {
            // concat() returns a NEW collection, so the cached list itself is not modified.
            $categories = $categories->concat([$ticket->category])->sortBy('name');
        }

        return view('tickets.edit', [
            'ticket' => $ticket,
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, Ticket $ticket)
    {
        Gate::authorize('update', $ticket);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'priority' => ['required', Rule::in(array_keys(Ticket::PRIORITIES))],
        ]);

        // A NEW category must be active. Keeping the current (possibly inactive) one is allowed.
        $newCategoryId = (int) $validated['category_id'];
        if ($newCategoryId !== $ticket->category_id) {
            $isActive = Category::where('id', $newCategoryId)->where('is_active', true)->exists();
            if (! $isActive) {
                throw ValidationException::withMessages(['category_id' => 'Please choose an active category.']);
            }
        }

        $changed = $this->ticketService->updateTicket($ticket, $validated, $request->user());

        $message = $changed ? 'Ticket updated successfully.' : 'No changes were made.';

        return redirect()->route('tickets.show', $ticket)->with('success', $message);
    }

    // =========================================================================
    // Assignment (admin only: route middleware role:admin + policy)
    // =========================================================================

    public function assign(Request $request, Ticket $ticket)
    {
        Gate::authorize('assign', $ticket);

        $validated = $request->validate([
            'agent_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $agent = User::findOrFail($validated['agent_id']);

        $changed = $this->ticketService->assignAgent($ticket, $agent, $request->user());

        if (! $changed) {
            return back()->with('info', "The ticket is already assigned to {$agent->name}. Nothing was changed.");
        }

        return back()->with('success', "Ticket assigned to {$agent->name}.");
    }

    public function autoAssign(Request $request, Ticket $ticket)
    {
        Gate::authorize('assign', $ticket);

        $agent = $this->ticketService->autoAssign($ticket, $request->user());

        if ($agent === null) {
            return back()->with('error', 'No active support agent is available. The ticket remains Open.');
        }

        return back()->with('success', "Ticket automatically assigned to {$agent->name} (lowest current workload).");
    }

    // =========================================================================
    // Status transitions
    // =========================================================================

    public function start(Request $request, Ticket $ticket)
    {
        Gate::authorize('start', $ticket);

        $this->ticketService->startWork($ticket, $request->user());

        return back()->with('success', 'Ticket is now In Progress.');
    }

    public function resolve(Request $request, Ticket $ticket)
    {
        Gate::authorize('resolve', $ticket);

        $validated = $request->validate([
            'resolution_note' => ['required', 'string', 'max:5000'],
        ]);

        $this->ticketService->resolve($ticket, $validated['resolution_note'], $request->user());

        return back()->with('success', 'Ticket marked as Resolved.');
    }

    public function reopen(Request $request, Ticket $ticket)
    {
        Gate::authorize('reopen', $ticket);

        $this->ticketService->reopen($ticket, $request->user());

        return back()->with('success', 'Ticket reopened and moved back to In Progress.');
    }

    public function close(Request $request, Ticket $ticket)
    {
        Gate::authorize('close', $ticket);

        $this->ticketService->close($ticket, $request->user());

        return back()->with('success', 'Ticket closed. Thank you for confirming the resolution.');
    }

    // =========================================================================

    /** Active agents with their current workload, for dropdowns and filters. */
    private function agentList()
    {
        return User::where('role', User::ROLE_AGENT)
            ->where('is_active', true)
            ->withCount(['assignedTickets as workload_count' => function ($query) {
                $query->whereIn('status', Ticket::WORKLOAD_STATUSES);
            }])
            ->orderBy('name')
            ->get();
    }
}
