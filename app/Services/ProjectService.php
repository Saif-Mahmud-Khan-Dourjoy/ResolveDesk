<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * V2: changes to project associations (project <-> clients, project <-> agents).
 *
 * Why a service? Each change needs several coordinated rules:
 *   - newly added clients/agents must be ACTIVE,
 *   - a removal is blocked while unresolved or reopenable tickets depend on it,
 *   - the check and the change must happen atomically (transaction + row lock).
 *
 * Concurrency: we lock the PROJECT row (SELECT ... FOR UPDATE) first.
 * Ticket creation and ticket assignment take a SHARED lock on the same project row
 * before they read the pivot tables, so "remove agent X" and "assign ticket to
 * agent X" cannot interleave and break the rule. Lock order everywhere:
 * project row -> ticket row (consistent order = no deadlock between them).
 */
class ProjectService
{
    /**
     * Replace the project's agent list with $agentIds.
     *
     * @param  array<int>  $agentIds
     * @return array{added: int, removed: int}
     */
    public function syncAgents(Project $project, array $agentIds): array
    {
        $agentIds = array_values(array_unique(array_map('intval', $agentIds)));

        // 3 = retry the whole transaction up to 3 times if MySQL reports a deadlock.
        $result = DB::transaction(function () use ($project, $agentIds) {
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();

            $currentIds = $lockedProject->agents()->pluck('users.id')->all();
            $addedIds = array_values(array_diff($agentIds, $currentIds));
            $removedIds = array_values(array_diff($currentIds, $agentIds));

            // Rule 1: only ACTIVE support agents can be newly associated.
            if (count($addedIds) > 0) {
                $validAddedCount = User::whereIn('id', $addedIds)
                    ->where('role', User::ROLE_AGENT)
                    ->where('is_active', true)
                    ->count();

                if ($validAddedCount !== count($addedIds)) {
                    throw ValidationException::withMessages([
                        'agent_ids' => 'Only active support agents can be added to a project.',
                    ]);
                }
            }

            // Rule 2: an agent still responsible for unresolved or reopenable tickets
            // of this project stays associated until those tickets are reassigned or closed.
            foreach ($removedIds as $agentId) {
                $dependentTickets = Ticket::where('project_id', $lockedProject->id)
                    ->where('assigned_to', $agentId)
                    ->whereIn('status', Ticket::OPEN_OR_REOPENABLE_STATUSES)
                    ->count();

                if ($dependentTickets > 0) {
                    $agentName = User::whereKey($agentId)->value('name');
                    throw ValidationException::withMessages([
                        'agent_ids' => "{$agentName} still has {$dependentTickets} unresolved or reopenable ticket(s) in this project. "
                            . 'Reassign or close them before removing the agent.',
                    ]);
                }
            }

            // sync() inserts the missing pivot rows and deletes the removed ones.
            $lockedProject->agents()->sync($agentIds);

            return ['added' => count($addedIds), 'removed' => count($removedIds)];
        }, 3);

        $this->clearCaches();

        return $result;
    }

    /**
     * Replace the project's client list with $clientIds.
     *
     * @param  array<int>  $clientIds
     * @return array{added: int, removed: int}
     */
    public function syncClients(Project $project, array $clientIds): array
    {
        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));

        $result = DB::transaction(function () use ($project, $clientIds) {
            $lockedProject = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();

            $currentIds = $lockedProject->clients()->pluck('clients.id')->all();
            $addedIds = array_values(array_diff($clientIds, $currentIds));
            $removedIds = array_values(array_diff($currentIds, $clientIds));

            // Rule 1: only ACTIVE clients can be newly associated.
            if (count($addedIds) > 0) {
                $validAddedCount = Client::whereIn('id', $addedIds)->where('is_active', true)->count();

                if ($validAddedCount !== count($addedIds)) {
                    throw ValidationException::withMessages([
                        'client_ids' => 'Only active clients can be added to a project.',
                    ]);
                }
            }

            // Rule 2: keep the association while this client's tickets for this project
            // are unresolved or reopenable. Closed tickets keep their history either way.
            foreach ($removedIds as $clientId) {
                $dependentTickets = Ticket::where('project_id', $lockedProject->id)
                    ->where('client_id', $clientId)
                    ->whereIn('status', Ticket::OPEN_OR_REOPENABLE_STATUSES)
                    ->count();

                if ($dependentTickets > 0) {
                    $clientName = Client::whereKey($clientId)->value('name');
                    throw ValidationException::withMessages([
                        'client_ids' => "{$clientName} still has {$dependentTickets} unresolved or reopenable ticket(s) in this project. "
                            . 'Close them before removing the client.',
                    ]);
                }
            }

            $lockedProject->clients()->sync($clientIds);

            return ['added' => count($addedIds), 'removed' => count($removedIds)];
        }, 3);

        $this->clearCaches();

        return $result;
    }

    private function clearCaches(): void
    {
        Cache::forget(TicketService::DASHBOARD_CACHE_KEY);
    }
}
