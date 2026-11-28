<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketRequest;
use App\Models\Category;
use App\Models\Client;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAssignmentService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ticket pages (list, create, show, edit) and workflow buttons
 * (assign, auto assign, start, resolve, reopen, close).
 *
 * Flow of every workflow action:
 *   route -> middleware (auth, active[, role:admin]) -> this controller
 *   -> Gate::authorize() (TicketPolicy) -> validation -> service -> redirect with message
 *
 * V2: every mutation form sends the hidden "version" field (optimistic locking).
 */
class TicketController extends Controller
{
    /** Tickets per page in the list. */
    private const PER_PAGE = 15;

    /**
     * Laravel's service container creates the services automatically and
     * passes them in here (constructor dependency injection).
     */
    public function __construct(
        private TicketService $ticketService,
        private TicketAssignmentService $assignmentService,
    ) {}

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
            'project_id' => ['nullable', 'regex:/^(global|\d+)$/'],   // a project id or "global"
            'client_id' => ['nullable', 'regex:/^(internal|\d+)$/'],  // a client id or "internal"
            'assigned_to' => ['nullable', 'regex:/^(unassigned|\d+)$/'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        // with(): eager loading - one extra query per relationship for the whole
        // page instead of one per row (avoids the N+1 problem).
        $query = Ticket::with(['category', 'project', 'client', 'creator', 'assignedAgent'])
            ->visibleTo($user); // authorization FIRST, filters only narrow it further

        $this->applyFilters($query, $request, $user);

        // withQueryString(): keeps the filters in the pagination links.
        $tickets = $query->latest()->paginate(self::PER_PAGE)->withQueryString();

        return view('tickets.index', [
            'tickets' => $tickets,
            'categories' => Category::orderBy('name')->get(['id', 'name']), // include inactive for filtering old tickets
            // Admin: every project. Others: only projects that appear in tickets they may see,
            // so a customer never learns the names of other clients' products.
            'projects' => $user->isAdmin()
                ? Project::orderBy('code')->get(['id', 'code', 'name'])
                : Project::whereIn('id', Ticket::visibleTo($user)->whereNotNull('project_id')->select('project_id'))
                    ->orderBy('code')->get(['id', 'code', 'name']),
            'clients' => $user->isAdmin() ? Client::orderBy('code')->get(['id', 'code', 'name']) : collect(),
            'agents' => $user->isAdmin() ? $this->activeAgents() : collect(),
        ]);
    }

    /** Shared by the ticket list and the report page/CSV export (same rules everywhere). */
    public static function applyFilters($query, Request $request, User $user): void
    {
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        foreach (['status', 'priority', 'category_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->input($column));
            }
        }

        if ($request->filled('project_id')) {
            if ($request->input('project_id') === 'global') {
                $query->whereNull('project_id');
            } else {
                $query->where('project_id', (int) $request->input('project_id'));
            }
        }

        // Client and agent filters are admin-only (others already see only their own tickets).
        if ($user->isAdmin() && $request->filled('client_id')) {
            if ($request->input('client_id') === 'internal') {
                $query->whereNull('client_id');
            } else {
                $query->where('client_id', (int) $request->input('client_id'));
            }
        }

        if ($user->isAdmin() && $request->filled('assigned_to')) {
            if ($request->input('assigned_to') === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', (int) $request->input('assigned_to'));
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
    }

    // =========================================================================
    // Create
    // =========================================================================

    public function create(Request $request)
    {
        Gate::authorize('create', Ticket::class);

        $user = $request->user();

        return view('tickets.create', [
            'categories' => Category::activeForDropdown(),
            // Admin chooses a client; customers' client is fixed; agents create internal tickets.
            'clients' => $user->isAdmin() ? Client::active()->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'projects' => $this->selectableProjects($user, null),
            // Idempotency key: a new random UUID every time the form is opened.
            'requestToken' => old('request_token', (string) Str::uuid()),
        ]);
    }

    public function store(StoreTicketRequest $request)
    {
        // StoreTicketRequest already authorized and validated the input.
        $ticket = $this->ticketService->createTicket(
            $request->safe()->except('attachments'),
            $request->user(),
            $request->file('attachments', [])
        );

        $message = $ticket->wasRecentlyCreated
            ? "Ticket {$ticket->ticket_number} was created successfully."
            : "This form was already submitted. Showing ticket {$ticket->ticket_number}.";

        return redirect()->route('tickets.show', $ticket)->with('success', $message);
    }

    /**
     * GET /tickets/project-options?client_id=5  (Fetch API, dependent dropdown)
     * Returns the projects the CURRENT user may choose. This only fills the
     * dropdown; TicketService re-validates the choice when the ticket is saved.
     */
    public function projectOptions(Request $request)
    {
        Gate::authorize('create', Ticket::class);

        $request->validate(['client_id' => ['nullable', 'integer']]);

        $clientId = $request->filled('client_id') ? (int) $request->input('client_id') : null;
        $projects = $this->selectableProjects($request->user(), $clientId);

        return response()->json([
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'label' => $project->displayName(),
            ])->values(),
        ]);
    }

    /**
     * Active projects a user may select for a NEW ticket.
     *   customer: projects of their own client organization (none if standalone)
     *   agent:    projects they support
     *   admin:    projects of the chosen client, or all active projects
     */
    private function selectableProjects(User $user, ?int $clientId)
    {
        $query = Project::active()->orderBy('code');

        if ($user->isCustomer()) {
            if ($user->client_id === null) {
                return collect();
            }
            $clientId = $user->client_id; // never the requested one
        }

        if ($user->isAgent()) {
            $query->whereHas('agents', fn ($agentQuery) => $agentQuery->where('users.id', $user->id));
        } elseif ($clientId !== null) {
            $query->whereHas('clients', fn ($clientQuery) => $clientQuery->where('clients.id', $clientId));
        }

        return $query->get(['id', 'code', 'name']);
    }

    // =========================================================================
    // Show
    // =========================================================================

    public function show(Request $request, Ticket $ticket)
    {
        Gate::authorize('view', $ticket);

        $user = $request->user();

        // Eager load what the page displays. Customers never receive internal notes
        // or internal activities: they are filtered out in the SQL query itself.
        $ticket->load([
            'category', 'project', 'client', 'creator', 'assignedAgent',
            'comments' => fn ($query) => $query->visibleTo($user)->with(['user', 'attachments']),
            'activities' => fn ($query) => $query->visibleTo($user)->with('user'),
            'attachments' => fn ($query) => $query->whereNull('ticket_comment_id'),
        ]);

        return view('tickets.show', [
            'ticket' => $ticket,
            'agents' => $user->isAdmin() ? $this->assignmentService->eligibleAgents($ticket) : collect(),
        ]);
    }

    // =========================================================================
    // Edit (subject, description, category, priority - never status or scope)
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
            'ticket' => $ticket->load(['project', 'client']),
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
            'version' => ['required', 'integer'], // optimistic locking
        ]);

        // A NEW category must be active. Keeping the current (possibly inactive) one is allowed.
        $newCategoryId = (int) $validated['category_id'];
        if ($newCategoryId !== $ticket->category_id) {
            $isActive = Category::where('id', $newCategoryId)->where('is_active', true)->exists();
            if (! $isActive) {
                throw ValidationException::withMessages(['category_id' => 'Please choose an active category.']);
            }
        }

        $expectedVersion = (int) $validated['version'];
        unset($validated['version']);

        $changed = $this->ticketService->updateTicket($ticket, $validated, $expectedVersion, $request->user());

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
            'version' => ['nullable', 'integer'],
        ]);

        $agent = User::findOrFail($validated['agent_id']);

        $changed = $this->assignmentService->assignAgent($ticket, $agent, $request->user(), $this->expectedVersion($request));

        if (! $changed) {
            return back()->with('info', "The ticket is already assigned to {$agent->name}. Nothing was changed.");
        }

        return back()->with('success', "Ticket assigned to {$agent->name}.");
    }

    public function autoAssign(Request $request, Ticket $ticket)
    {
        Gate::authorize('assign', $ticket);
        $request->validate(['version' => ['nullable', 'integer']]);

        $agent = $this->assignmentService->autoAssign($ticket, $request->user(), $this->expectedVersion($request));

        if ($agent === null) {
            $scope = $ticket->project_id === null ? 'active support agent' : 'active support agent for this project';

            return back()->with('error', "No eligible {$scope} is available. The ticket remains Open.");
        }

        return back()->with('success', "Ticket automatically assigned to {$agent->name} (lowest current workload).");
    }

    // =========================================================================
    // Status transitions
    // =========================================================================

    public function start(Request $request, Ticket $ticket)
    {
        Gate::authorize('start', $ticket);
        $request->validate(['version' => ['nullable', 'integer']]);

        $this->ticketService->startWork($ticket, $request->user(), $this->expectedVersion($request));

        return back()->with('success', 'Ticket is now In Progress.');
    }

    public function resolve(Request $request, Ticket $ticket)
    {
        Gate::authorize('resolve', $ticket);

        $validated = $request->validate([
            'resolution_note' => ['required', 'string', 'max:5000'],
            'version' => ['nullable', 'integer'],
        ]);

        $this->ticketService->resolve($ticket, $validated['resolution_note'], $request->user(), $this->expectedVersion($request));

        return back()->with('success', 'Ticket marked as Resolved.');
    }

    public function reopen(Request $request, Ticket $ticket)
    {
        Gate::authorize('reopen', $ticket);
        $request->validate(['version' => ['nullable', 'integer']]);

        $this->ticketService->reopen($ticket, $request->user(), $this->expectedVersion($request));

        return back()->with('success', 'Ticket reopened and moved back to In Progress.');
    }

    public function close(Request $request, Ticket $ticket)
    {
        Gate::authorize('close', $ticket);
        $request->validate(['version' => ['nullable', 'integer']]);

        $this->ticketService->close($ticket, $request->user(), $this->expectedVersion($request));

        return back()->with('success', 'Ticket closed. Thank you for confirming the resolution.');
    }

    // =========================================================================

    /** The hidden "version" field of the form, or null when it was not sent. */
    private function expectedVersion(Request $request): ?int
    {
        return $request->filled('version') ? (int) $request->input('version') : null;
    }

    /** Active agents (for the admin list filter). */
    private function activeAgents()
    {
        return User::where('role', User::ROLE_AGENT)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
