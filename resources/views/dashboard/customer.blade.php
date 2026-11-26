@extends('layouts.app')

@section('title', 'My Dashboard')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Hello, {{ auth()->user()->name }}</h1>
        <p class="page-subtitle">Track your support requests.</p>
    </div>
    <a href="{{ route('tickets.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Create Ticket</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        @include('dashboard._stat-card', ['label' => 'My total tickets', 'value' => $counts['total'], 'icon' => 'bi-collection', 'color' => 'primary', 'link' => route('tickets.index')])
    </div>
    <div class="col-6 col-lg-3">
        @include('dashboard._stat-card', ['label' => 'Active', 'value' => $counts['active'], 'icon' => 'bi-hourglass-split', 'color' => 'warning'])
    </div>
    <div class="col-6 col-lg-3">
        @include('dashboard._stat-card', ['label' => 'Resolved (awaiting your confirmation)', 'value' => $counts['resolved'], 'icon' => 'bi-check2-circle', 'color' => 'success', 'link' => route('tickets.index', ['status' => 'resolved'])])
    </div>
    <div class="col-6 col-lg-3">
        @include('dashboard._stat-card', ['label' => 'Closed', 'value' => $counts['closed'], 'icon' => 'bi-lock', 'color' => 'secondary', 'link' => route('tickets.index', ['status' => 'closed'])])
    </div>
</div>

<div class="card">
    <div class="card-header">Recent ticket updates</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead><tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Agent</th><th>Last update</th></tr></thead>
            <tbody>
                @forelse ($recentTickets as $ticket)
                    <tr>
                        <td class="text-nowrap"><a href="{{ route('tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_number }}</a></td>
                        <td>
                            <div class="ticket-subject text-truncate">{{ $ticket->subject }}</div>
                            <div class="small text-muted">{{ $ticket->category->name }}</div>
                        </td>
                        <td class="text-nowrap">@include('partials.ticket-badges', ['ticket' => $ticket])</td>
                        <td class="text-nowrap small">{{ $ticket->assignedAgent?->name ?? 'Waiting for assignment' }}</td>
                        <td class="text-nowrap small">{{ $ticket->updated_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted text-center py-4">
                        You have not created any tickets yet. <a href="{{ route('tickets.create') }}">Create your first ticket</a>.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
