<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Client;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * V2: ticket report page + CSV export (administrators only, route group role:admin).
 *
 * Both actions build the SAME query with TicketController::applyFilters(), so the
 * page and the downloaded CSV always contain exactly the same tickets.
 *
 * Formulas (also shown on the page):
 *   SLA compliance % = resolved on/before due_at ÷ all Resolved/Closed tickets × 100
 *   Overdue          = active (Open/Assigned/In Progress) AND due_at < now
 *   Date range       = ticket created_at date, both ends inclusive
 */
class ReportController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->filteredQuery($request);

        // One GROUP BY query for the status breakdown of the filtered tickets.
        $statusCounts = (clone $query)->reorder()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $tickets = (clone $query)
            ->with(['project', 'client', 'category', 'creator', 'assignedAgent'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('reports.index', [
            'tickets' => $tickets,
            'summary' => Ticket::slaSummary((clone $query)->reorder()),
            'statusCounts' => $statusCounts,
            'projects' => Project::orderBy('code')->get(['id', 'code', 'name']),
            'clients' => Client::orderBy('code')->get(['id', 'code', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'agents' => User::where('role', User::ROLE_AGENT)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * GET /reports/export?...same filters...
     *
     * Streamed: rows are written to the browser while they are read, 500 at a time
     * with chunkById(), so a large export never loads every ticket into memory.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filteredQuery($request);
        $fileName = 'resolvedesk-tickets-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Bangla/accents correctly

            fputcsv($output, [
                'Ticket', 'Subject', 'Project', 'Client', 'Category', 'Priority', 'Status',
                'Created by', 'Assigned agent', 'Created at', 'Due at', 'Resolved at', 'SLA result',
            ]);

            $query->with(['project', 'client', 'category', 'creator', 'assignedAgent'])
                ->chunkById(500, function ($tickets) use ($output) {
                    foreach ($tickets as $ticket) {
                        fputcsv($output, array_map([$this, 'safeCsvValue'], [
                            $ticket->ticket_number,
                            $ticket->subject,
                            $ticket->project?->code ?? 'Global',
                            $ticket->client?->code ?? 'Internal',
                            $ticket->category->name,
                            $ticket->priorityLabel(),
                            $ticket->statusLabel(),
                            $ticket->creator->name,
                            $ticket->assignedAgent?->name ?? '',
                            $ticket->created_at->format('Y-m-d H:i'),
                            $ticket->due_at->format('Y-m-d H:i'),
                            $ticket->resolved_at?->format('Y-m-d H:i') ?? '',
                            $this->slaResult($ticket),
                        ]));
                    }
                });

            fclose($output);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * CSV formula injection protection: a cell starting with = + - @ (or tab/CR)
     * would be executed as a formula by Excel. Prefix it with ' so it stays text.
     * Example: a ticket subject "=HYPERLINK(...)" is exported as "'=HYPERLINK(...)".
     */
    public function safeCsvValue($value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function slaResult(Ticket $ticket): string
    {
        $withinSla = $ticket->resolvedWithinSla();

        if ($withinSla === true) {
            return 'Resolved within SLA';
        }
        if ($withinSla === false) {
            return 'Resolved after SLA';
        }

        return $ticket->isOverdue() ? 'Overdue' : 'Within SLA (open)';
    }

    /** Validates the filters and returns the filtered (admin: all tickets) query. */
    private function filteredQuery(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(Ticket::STATUSES))],
            'priority' => ['nullable', Rule::in(array_keys(Ticket::PRIORITIES))],
            'category_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'regex:/^(global|\d+)$/'],
            'client_id' => ['nullable', 'regex:/^(internal|\d+)$/'],
            'assigned_to' => ['nullable', 'regex:/^(unassigned|\d+)$/'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query = Ticket::visibleTo($request->user());
        TicketController::applyFilters($query, $request, $request->user());

        return $query;
    }
}
