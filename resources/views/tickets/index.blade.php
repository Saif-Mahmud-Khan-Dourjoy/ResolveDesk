@extends('layouts.app')

@section('title', 'Tickets')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Tickets</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ auth()->user()->isAdmin() ? 'All Tickets' : 'My Tickets' }}</h1>
        <p class="page-subtitle">
            @if (auth()->user()->isAdmin())
                Every ticket in the system.
            @else
                Tickets you created or that are assigned to you.
            @endif
        </p>
    </div>
    <a href="{{ route('tickets.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Create Ticket</a>
</div>

{{-- Filters: a GET form, so the filters appear in the URL and work with pagination. --}}
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('tickets.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-xl-3">
                <label for="search" class="form-label small mb-1">Search</label>
                <input type="text" id="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Ticket number or subject">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="status" class="form-label small mb-1">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All statuses</option>
                    @foreach (\App\Models\Ticket::STATUSES as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="priority" class="form-label small mb-1">Priority</label>
                <select id="priority" name="priority" class="form-select">
                    <option value="">All priorities</option>
                    @foreach (\App\Models\Ticket::PRIORITIES as $value => $label)
                        <option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label for="category_id" class="form-label small mb-1">Category</label>
                <select id="category_id" name="category_id" class="form-select">
                    <option value="">All categories</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label for="project_id" class="form-label small mb-1">Project</label>
                <select id="project_id" name="project_id" class="form-select">
                    <option value="">All projects</option>
                    <option value="global" @selected(request('project_id') === 'global')>Global (no project)</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) request('project_id') === (string) $project->id)>{{ $project->code }}</option>
                    @endforeach
                </select>
            </div>
            @if (auth()->user()->isAdmin())
                <div class="col-6 col-md-4 col-xl-2">
                    <label for="client_id" class="form-label small mb-1">Client</label>
                    <select id="client_id" name="client_id" class="form-select">
                        <option value="">All clients</option>
                        <option value="internal" @selected(request('client_id') === 'internal')>Internal (no client)</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((string) request('client_id') === (string) $client->id)>{{ $client->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-4 col-xl-3">
                    <label for="assigned_to" class="form-label small mb-1">Assigned agent</label>
                    <select id="assigned_to" name="assigned_to" class="form-select">
                        <option value="">Any agent</option>
                        <option value="unassigned" @selected(request('assigned_to') === 'unassigned')>Unassigned</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}" @selected((string) request('assigned_to') === (string) $agent->id)>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-6 col-md-4 col-xl-2">
                <label for="date_from" class="form-label small mb-1">Created from</label>
                <input type="date" id="date_from" name="date_from" value="{{ request('date_from') }}" class="form-control">
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label for="date_to" class="form-label small mb-1">Created to</label>
                <input type="date" id="date_to" name="date_to" value="{{ request('date_to') }}" class="form-control">
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="overdue" name="overdue" value="1" @checked(request()->boolean('overdue'))>
                    <label class="form-check-label" for="overdue">Overdue only</label>
                </div>
            </div>
            <div class="col-12 col-xl-auto ms-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="{{ route('tickets.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Subject</th>
                    <th>Project / Client</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Created by</th>
                    <th>Assigned agent</th>
                    <th>Due</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr class="{{ $ticket->isOverdue() ? 'row-overdue' : '' }}">
                        <td class="fw-semibold text-nowrap"><a href="{{ route('tickets.show', $ticket) }}" class="text-decoration-none">{{ $ticket->ticket_number }}</a></td>
                        <td><div class="ticket-subject text-truncate" title="{{ $ticket->subject }}">{{ $ticket->subject }}</div></td>
                        <td class="text-nowrap small">
                            {{ $ticket->project?->code ?? 'Global' }}
                            <div class="text-muted">{{ $ticket->client?->code ?? 'Internal' }}</div>
                        </td>
                        <td class="text-nowrap">{{ $ticket->category->name }}</td>
                        <td>@include('partials.ticket-badges', ['ticket' => $ticket, 'only' => 'priority'])</td>
                        <td class="text-nowrap">
                            @include('partials.ticket-badges', ['ticket' => $ticket, 'only' => 'status'])
                            @if ($ticket->isOverdue())
                                <span class="badge badge-overdue">Overdue</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $ticket->creator->name }}</td>
                        <td class="text-nowrap">{{ $ticket->assignedAgent?->name ?? '—' }}</td>
                        <td class="text-nowrap small {{ $ticket->isOverdue() ? 'text-danger fw-semibold' : '' }}">{{ $ticket->due_at->format('d M Y, h:i A') }}</td>
                        <td class="text-nowrap small">{{ $ticket->created_at->format('d M Y') }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('tickets.show', $ticket) }}" class="btn btn-sm btn-outline-primary">View</a>
                            @can('update', $ticket)
                                <a href="{{ route('tickets.edit', $ticket) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            No tickets match your filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($tickets->hasPages())
        <div class="card-footer bg-white">{{ $tickets->links() }}</div>
    @endif
</div>
@endsection
