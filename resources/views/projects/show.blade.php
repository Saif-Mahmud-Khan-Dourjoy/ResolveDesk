@extends('layouts.app')

@section('title', $project->code)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('projects.index') }}">Projects</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $project->code }}</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $project->code }} <span class="text-muted fw-normal">· {{ $project->name }}</span></h1>
        <p class="page-subtitle">@include('partials.active-badge', ['active' => $project->is_active]) {{ $project->description }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('tickets.index', ['project_id' => $project->id]) }}" class="btn btn-outline-primary"><i class="bi bi-ticket-detailed me-1"></i> All tickets</a>
        <a href="{{ route('projects.edit', $project) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i> Edit</a>
    </div>
</div>

<h2 class="h6 text-muted text-uppercase mb-2">SLA statistics</h2>
@include('partials.sla-stats', ['stats' => $stats])

<div class="row g-3 mb-4">
    {{-- Client organizations using this project --}}
    <div class="col-lg-6" id="clients">
        <div class="card h-100">
            <div class="card-header">Client organizations ({{ $project->clients->count() }})</div>
            <div class="card-body">
                <form method="POST" action="{{ route('projects.clients.update', $project) }}" data-disable-on-submit
                      data-confirm="Save the client list for {{ $project->code }}?">
                    @csrf
                    @method('PUT')
                    @php($selectedClientIds = old('client_ids', $project->clients->pluck('id')->all()))
                    @forelse ($clientChoices as $client)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="client_ids[]" value="{{ $client->id }}" id="client-{{ $client->id }}"
                                   @checked(in_array($client->id, array_map('intval', $selectedClientIds), true))>
                            <label class="form-check-label" for="client-{{ $client->id }}">
                                {{ $client->displayName() }}
                                @unless ($client->is_active)<span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>@endunless
                            </label>
                        </div>
                    @empty
                        <p class="text-muted small mb-2">No active clients exist yet. <a href="{{ route('clients.create') }}">Create one</a>.</p>
                    @endforelse
                    @error('client_ids')<div class="alert alert-danger small mt-2 mb-0">{{ $message }}</div>@enderror
                    <p class="form-text">A client cannot be removed while it has unresolved or reopenable tickets in this project.</p>
                    <button type="submit" class="btn btn-sm btn-primary">Save clients</button>
                </form>
            </div>
        </div>
    </div>

    {{-- Support agents eligible for assignment --}}
    <div class="col-lg-6" id="agents">
        <div class="card h-100">
            <div class="card-header">Support agents ({{ $project->agents->count() }})</div>
            <div class="card-body">
                <form method="POST" action="{{ route('projects.agents.update', $project) }}" data-disable-on-submit
                      data-confirm="Save the support agent list for {{ $project->code }}?">
                    @csrf
                    @method('PUT')
                    @php($selectedAgentIds = old('agent_ids', $project->agents->pluck('id')->all()))
                    @forelse ($agentChoices as $agent)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="agent_ids[]" value="{{ $agent->id }}" id="agent-{{ $agent->id }}"
                                   @checked(in_array($agent->id, array_map('intval', $selectedAgentIds), true))>
                            <label class="form-check-label" for="agent-{{ $agent->id }}">
                                {{ $agent->name }}
                                @unless ($agent->is_active)<span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>@endunless
                            </label>
                        </div>
                    @empty
                        <p class="text-muted small mb-2">No active support agents exist.</p>
                    @endforelse
                    @error('agent_ids')<div class="alert alert-danger small mt-2 mb-0">{{ $message }}</div>@enderror
                    <p class="form-text">Only these agents can be assigned this project's tickets. Membership does NOT give access to all project tickets.</p>
                    <button type="submit" class="btn btn-sm btn-primary">Save agents</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Latest tickets</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead><tr><th>Ticket</th><th>Subject</th><th>Client</th><th>Status</th><th>Agent</th><th>Due</th></tr></thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr>
                        <td><a href="{{ route('tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_number }}</a></td>
                        <td><div class="ticket-subject text-truncate">{{ $ticket->subject }}</div></td>
                        <td>{{ $ticket->client?->code ?? 'Internal' }}</td>
                        <td class="text-nowrap">@include('partials.ticket-badges', ['ticket' => $ticket])</td>
                        <td>{{ $ticket->assignedAgent?->name ?? '—' }}</td>
                        <td class="text-nowrap small">{{ $ticket->due_at->format('d M Y, h:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted text-center py-4">No tickets for this project yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($tickets->hasPages())
        <div class="card-footer bg-white">{{ $tickets->links() }}</div>
    @endif
</div>
@endsection
