@extends('layouts.app')

@section('title', 'Reports')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Reports</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Ticket Report</h1>
        <p class="page-subtitle">Filter tickets and export exactly the same rows to CSV.</p>
    </div>
    <a href="{{ route('reports.export', request()->query()) }}" class="btn btn-outline-primary"><i class="bi bi-download me-1"></i> Export CSV</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.index') }}" class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label for="project_id" class="form-label small mb-1">Project / scope</label>
                <select id="project_id" name="project_id" class="form-select">
                    <option value="">All</option>
                    <option value="global" @selected(request('project_id') === 'global')>Global only</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="client_id" class="form-label small mb-1">Client</label>
                <select id="client_id" name="client_id" class="form-select">
                    <option value="">All</option>
                    <option value="internal" @selected(request('client_id') === 'internal')>Internal (no client)</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) request('client_id') === (string) $client->id)>{{ $client->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="assigned_to" class="form-label small mb-1">Agent</label>
                <select id="assigned_to" name="assigned_to" class="form-select">
                    <option value="">All</option>
                    <option value="unassigned" @selected(request('assigned_to') === 'unassigned')>Unassigned</option>
                    @foreach ($agents as $agent)
                        <option value="{{ $agent->id }}" @selected((string) request('assigned_to') === (string) $agent->id)>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="status" class="form-label small mb-1">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All</option>
                    @foreach (\App\Models\Ticket::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="priority" class="form-label small mb-1">Priority</label>
                <select id="priority" name="priority" class="form-select">
                    <option value="">All</option>
                    @foreach (\App\Models\Ticket::PRIORITIES as $value => $label)
                        <option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="category_id" class="form-label small mb-1">Category</label>
                <select id="category_id" name="category_id" class="form-select">
                    <option value="">All</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="date_from" class="form-label small mb-1">Created from</label>
                <input type="date" id="date_from" name="date_from" value="{{ request('date_from') }}" class="form-control @error('date_from') is-invalid @enderror">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="date_to" class="form-label small mb-1">Created to</label>
                <input type="date" id="date_to" name="date_to" value="{{ request('date_to') }}" class="form-control @error('date_to') is-invalid @enderror">
                @error('date_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Apply</button>
                <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

@include('partials.sla-stats', ['stats' => $summary])

<div class="d-flex flex-wrap gap-2 mb-3 small">
    @foreach (\App\Models\Ticket::STATUSES as $value => $label)
        <span class="badge badge-status-{{ $value }}">{{ $label }}: {{ (int) ($statusCounts[$value] ?? 0) }}</span>
    @endforeach
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead>
                <tr><th>Ticket</th><th>Subject</th><th>Project</th><th>Client</th><th>Status</th><th>Agent</th><th>Created</th><th>Due</th><th>SLA</th></tr>
            </thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr>
                        <td><a href="{{ route('tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_number }}</a></td>
                        <td><div class="ticket-subject text-truncate">{{ $ticket->subject }}</div></td>
                        <td>{{ $ticket->project?->code ?? 'Global' }}</td>
                        <td>{{ $ticket->client?->code ?? 'Internal' }}</td>
                        <td class="text-nowrap">@include('partials.ticket-badges', ['ticket' => $ticket])</td>
                        <td class="text-nowrap">{{ $ticket->assignedAgent?->name ?? '—' }}</td>
                        <td class="text-nowrap small">{{ $ticket->created_at->format('d M Y') }}</td>
                        <td class="text-nowrap small">{{ $ticket->due_at->format('d M Y, h:i A') }}</td>
                        <td class="small text-nowrap">
                            @if ($ticket->resolvedWithinSla() === true)
                                <span class="text-success">Within SLA</span>
                            @elseif ($ticket->resolvedWithinSla() === false)
                                <span class="text-danger">After SLA</span>
                            @else
                                <span class="text-muted">Open</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No tickets match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($tickets->hasPages())
        <div class="card-footer bg-white">{{ $tickets->links() }}</div>
    @endif
</div>

<p class="small text-muted mt-3">
    SLA compliance = tickets resolved on or before their deadline ÷ all Resolved/Closed tickets. Overdue = still active and past the deadline.
    Date range uses the ticket creation date (both ends inclusive).
</p>
@endsection
