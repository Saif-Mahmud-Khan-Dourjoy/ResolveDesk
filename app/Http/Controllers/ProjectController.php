<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * V2: project management (administrators only - role:admin route group).
 *
 * Plain CRUD stays here. Association changes (clients/agents) go through
 * ProjectService because they need a transaction, a row lock and removal rules.
 * Projects are never deleted (tickets reference them); they are deactivated.
 */
class ProjectController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private ProjectService $projectService) {}

    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        // withCount(): one sub-query per count instead of loading the related rows.
        $query = Project::withCount(['clients', 'agents', 'tickets']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $projects = $query->orderBy('name')->paginate(self::PER_PAGE)->withQueryString();

        return view('projects.index', ['projects' => $projects]);
    }

    public function create()
    {
        return view('projects.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validateProject($request);

        $project = Project::create($validated);
        $this->clearCaches();

        return redirect()->route('projects.show', $project)->with('success', "Project {$project->code} was created.");
    }

    /** Overview, clients, agents (with association forms), tickets and SLA statistics. */
    public function show(Project $project)
    {
        $project->load(['clients', 'agents']);

        $tickets = Ticket::with(['client', 'creator', 'assignedAgent'])
            ->where('project_id', $project->id)
            ->latest()
            ->paginate(10);

        return view('projects.show', [
            'project' => $project,
            'tickets' => $tickets,
            'stats' => Ticket::slaSummary(Ticket::where('project_id', $project->id)),
            // Choices for the association forms. Inactive records that are ALREADY
            // associated are still listed (checked) so saving does not remove them by accident.
            'clientChoices' => Client::where('is_active', true)
                ->orWhereIn('id', $project->clients->pluck('id'))
                ->orderBy('name')->get(),
            'agentChoices' => User::where('role', User::ROLE_AGENT)
                ->where(function ($query) use ($project) {
                    $query->where('is_active', true)->orWhereIn('id', $project->agents->pluck('id'));
                })
                ->orderBy('name')->get(),
        ]);
    }

    public function edit(Project $project)
    {
        return view('projects.edit', ['project' => $project]);
    }

    public function update(Request $request, Project $project)
    {
        $validated = $this->validateProject($request, $project);

        // Deactivating is always allowed: existing tickets stay viewable and resolvable,
        // the project only disappears from NEW ticket forms.
        $project->update($validated);
        $this->clearCaches();

        return redirect()->route('projects.show', $project)->with('success', 'Project updated.');
    }

    /** PUT /projects/{project}/clients  (checkbox list on the project page) */
    public function updateClients(Request $request, Project $project)
    {
        $validated = $request->validate([
            'client_ids' => ['array'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
        ]);

        $result = $this->projectService->syncClients($project, $validated['client_ids'] ?? []);

        return redirect()->to(route('projects.show', $project) . '#clients')
            ->with('success', "Clients updated ({$result['added']} added, {$result['removed']} removed).");
    }

    /** PUT /projects/{project}/agents  (checkbox list on the project page) */
    public function updateAgents(Request $request, Project $project)
    {
        $validated = $request->validate([
            'agent_ids' => ['array'],
            'agent_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $result = $this->projectService->syncAgents($project, $validated['agent_ids'] ?? []);

        return redirect()->to(route('projects.show', $project) . '#agents')
            ->with('success', "Support agents updated ({$result['added']} added, {$result['removed']} removed).");
    }

    private function validateProject(Request $request, ?Project $project = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            // Unique code; when editing, ignore this project's own row.
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('projects', 'code')->ignore($project?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $validated['code'] = strtoupper($validated['code']);
        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }

    private function clearCaches(): void
    {
        Cache::forget(TicketService::DASHBOARD_CACHE_KEY);
    }
}
