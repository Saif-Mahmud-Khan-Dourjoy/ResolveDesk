<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One /dashboard URL, three different dashboards depending on the user's role.
 * All numbers come from real database queries.
 */
class DashboardController extends Controller
{
    /** Admin statistics are cached for 60 seconds (and cleared after ticket changes). */
    private const ADMIN_STATS_TTL_SECONDS = 60;

    /** Number of days shown in the "ticket creation trend" chart. */
    private const TREND_DAYS = 14;

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return $this->adminDashboard();
        }

        if ($user->isAgent()) {
            return $this->agentDashboard($user);
        }

        return $this->customerDashboard($user);
    }

    // =========================================================================
    // Administrator
    // =========================================================================

    private function adminDashboard()
    {
        // Cache-aside: read from cache, or run buildAdminStatistics() and store the result.
        $stats = Cache::remember(TicketService::DASHBOARD_CACHE_KEY, self::ADMIN_STATS_TTL_SECONDS, function () {
            return $this->buildAdminStatistics();
        });

        // NOT cached: these must always be current.
        $overdueCount = Ticket::overdue()->count();

        $recentTickets = Ticket::with(['category', 'creator', 'assignedAgent'])
            ->latest()
            ->take(8)
            ->get();

        $agentWorkload = User::where('role', User::ROLE_AGENT)
            ->withCount([
                'assignedTickets as active_count' => function ($query) {
                    $query->whereIn('status', Ticket::WORKLOAD_STATUSES);
                },
                'assignedTickets as overdue_count' => function ($query) {
                    $query->overdue();
                },
                'assignedTickets as resolved_count' => function ($query) {
                    $query->whereIn('status', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED]);
                },
            ])
            ->orderBy('name')
            ->get();

        return view('dashboard.admin', [
            'stats' => $stats,
            'overdueCount' => $overdueCount,
            'recentTickets' => $recentTickets,
            'agentWorkload' => $agentWorkload,
        ]);
    }

    /**
     * The expensive aggregate queries. Runs only on a cache miss.
     * Returns a plain array so it can be stored in the cache table.
     */
    private function buildAdminStatistics(): array
    {
        // SELECT status, COUNT(*) AS total FROM tickets GROUP BY status
        $countsByStatus = Ticket::select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $statusCounts = [];
        foreach (Ticket::STATUSES as $status => $label) {
            $statusCounts[$status] = (int) ($countsByStatus[$status] ?? 0);
        }

        // SELECT priority, COUNT(*) AS total FROM tickets GROUP BY priority
        $countsByPriority = Ticket::select('priority', DB::raw('COUNT(*) AS total'))
            ->groupBy('priority')
            ->pluck('total', 'priority');

        $priorityCounts = [];
        foreach (Ticket::PRIORITIES as $priority => $label) {
            $priorityCounts[$label] = (int) ($countsByPriority[$priority] ?? 0);
        }

        // Category name => number of tickets (includes inactive categories).
        $categoryCounts = Category::withCount('tickets')
            ->orderBy('name')
            ->pluck('tickets_count', 'name')
            ->toArray();

        // Tickets created per day for the last 14 days.
        $trendStart = now()->subDays(self::TREND_DAYS - 1)->startOfDay();
        $countsByDay = Ticket::where('created_at', '>=', $trendStart)
            ->select(DB::raw('DATE(created_at) AS day'), DB::raw('COUNT(*) AS total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        $trend = [];
        for ($i = 0; $i < self::TREND_DAYS; $i++) {
            $date = $trendStart->copy()->addDays($i);
            $trend[$date->format('d M')] = (int) ($countsByDay[$date->format('Y-m-d')] ?? 0);
        }

        // Average hours from creation to (latest) resolution, for Resolved and Closed tickets.
        // TIMESTAMPDIFF is a MySQL/MariaDB function.
        $averageMinutes = Ticket::whereIn('status', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->whereNotNull('resolved_at')
            ->value(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))'));

        $averageResolutionHours = $averageMinutes !== null ? round($averageMinutes / 60, 1) : null;

        return [
            'total' => array_sum($statusCounts),
            'status_counts' => $statusCounts,
            'priority_counts' => $priorityCounts,
            'category_counts' => $categoryCounts,
            'trend' => $trend,
            'average_resolution_hours' => $averageResolutionHours,
            'generated_at' => now()->format('h:i:s A'),
        ];
    }

    // =========================================================================
    // Support agent (not cached)
    // =========================================================================

    private function agentDashboard(User $agent)
    {
        $counts = [
            'assigned_total' => Ticket::where('assigned_to', $agent->id)->count(),
            'pending' => Ticket::where('assigned_to', $agent->id)->where('status', Ticket::STATUS_ASSIGNED)->count(),
            'in_progress' => Ticket::where('assigned_to', $agent->id)->where('status', Ticket::STATUS_IN_PROGRESS)->count(),
            'resolved' => Ticket::where('assigned_to', $agent->id)
                ->whereIn('status', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])->count(),
            'overdue' => Ticket::where('assigned_to', $agent->id)->overdue()->count(),
        ];

        $recentTickets = Ticket::with(['category', 'creator'])
            ->where('assigned_to', $agent->id)
            ->latest('updated_at')
            ->take(8)
            ->get();

        $overdueTickets = Ticket::with(['category', 'creator'])
            ->where('assigned_to', $agent->id)
            ->overdue()
            ->orderBy('due_at')
            ->take(5)
            ->get();

        return view('dashboard.agent', [
            'counts' => $counts,
            'recentTickets' => $recentTickets,
            'overdueTickets' => $overdueTickets,
        ]);
    }

    // =========================================================================
    // Customer (not cached)
    // =========================================================================

    private function customerDashboard(User $customer)
    {
        $counts = [
            'total' => Ticket::where('created_by', $customer->id)->count(),
            'active' => Ticket::where('created_by', $customer->id)->whereIn('status', Ticket::ACTIVE_STATUSES)->count(),
            'resolved' => Ticket::where('created_by', $customer->id)->where('status', Ticket::STATUS_RESOLVED)->count(),
            'closed' => Ticket::where('created_by', $customer->id)->where('status', Ticket::STATUS_CLOSED)->count(),
        ];

        // "Recent ticket updates" = the customer's tickets that changed most recently.
        $recentTickets = Ticket::with(['category', 'assignedAgent'])
            ->where('created_by', $customer->id)
            ->latest('updated_at')
            ->take(8)
            ->get();

        return view('dashboard.customer', [
            'counts' => $counts,
            'recentTickets' => $recentTickets,
        ]);
    }
}
