@extends('layouts.app')

@section('title', $client->code)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('clients.index') }}">Clients</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $client->code }}</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $client->code }} <span class="text-muted fw-normal">· {{ $client->name }}</span></h1>
        <p class="page-subtitle">
            @include('partials.active-badge', ['active' => $client->is_active])
            {{ $client->email }} @if ($client->phone) · {{ $client->phone }} @endif
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('tickets.index', ['client_id' => $client->id]) }}" class="btn btn-outline-primary"><i class="bi bi-ticket-detailed me-1"></i> All tickets</a>
        <a href="{{ route('clients.edit', $client) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>
</div>

@if ($client->address || $client->description)
    <div class="card mb-4"><div class="card-body small">
        @if ($client->address)<div><span class="text-muted">Address:</span> {{ $client->address }}</div>@endif
        @if ($client->description)<div class="mt-1">{{ $client->description }}</div>@endif
    </div></div>
@endif

<h2 class="h6 text-muted text-uppercase mb-2">SLA statistics</h2>
@include('partials.sla-stats', ['stats' => $stats])

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Projects ({{ $client->projects->count() }})</div>
            <ul class="list-group list-group-flush">
                @forelse ($client->projects as $project)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="{{ route('projects.show', $project) }}" class="text-decoration-none">{{ $project->displayName() }}</a>
                        @include('partials.active-badge', ['active' => $project->is_active])
                    </li>
                @empty
                    <li class="list-group-item text-muted small">Not associated with any project. Associations are managed on each project's page.</li>
                @endforelse
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Customer users ({{ $client->customers->count() }})</div>
            <ul class="list-group list-group-flush">
                @forelse ($client->customers as $customer)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <a href="{{ route('users.show', $customer) }}" class="text-decoration-none">{{ $customer->name }} <span class="text-muted small">{{ $customer->email }}</span></a>
                        @include('partials.active-badge', ['active' => $customer->is_active])
                    </li>
                @empty
                    <li class="list-group-item text-muted small">No customer users. Set a customer's client on the user edit page.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Latest tickets</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead><tr><th>Ticket</th><th>Subject</th><th>Project</th><th>Status</th><th>Created by</th><th>Agent</th></tr></thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr>
                        <td><a href="{{ route('tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_number }}</a></td>
                        <td><div class="ticket-subject text-truncate">{{ $ticket->subject }}</div></td>
                        <td>{{ $ticket->project?->code ?? 'Global' }}</td>
                        <td class="text-nowrap">@include('partials.ticket-badges', ['ticket' => $ticket])</td>
                        <td>{{ $ticket->creator->name }}</td>
                        <td>{{ $ticket->assignedAgent?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted text-center py-4">No tickets for this client yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($tickets->hasPages())
        <div class="card-footer bg-white">{{ $tickets->links() }}</div>
    @endif
</div>
@endsection
