@extends('layouts.app')

@section('title', 'My Dashboard')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Welcome back, {{ auth()->user()->name }}</h1>
        <p class="page-subtitle">Your personal support workload.</p>
    </div>
    <a href="{{ route('tickets.index') }}" class="btn btn-outline-primary"><i class="bi bi-ticket-detailed me-1"></i> My Tickets</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg">
        @include('dashboard._stat-card', ['label' => 'My assigned tickets', 'value' => $counts['assigned_total'], 'icon' => 'bi-collection', 'color' => 'primary'])
    </div>
    <div class="col-6 col-lg">
        @include('dashboard._stat-card', ['label' => 'Pending (not started)', 'value' => $counts['pending'], 'icon' => 'bi-inbox', 'color' => 'info', 'link' => route('tickets.index', ['status' => 'assigned'])])
    </div>
    <div class="col-6 col-lg">
        @include('dashboard._stat-card', ['label' => 'In progress', 'value' => $counts['in_progress'], 'icon' => 'bi-hourglass-split', 'color' => 'warning', 'link' => route('tickets.index', ['status' => 'in_progress'])])
    </div>
    <div class="col-6 col-lg">
        @include('dashboard._stat-card', ['label' => 'Resolved / closed', 'value' => $counts['resolved'], 'icon' => 'bi-check2-circle', 'color' => 'success'])
    </div>
    <div class="col-12 col-lg">
        @include('dashboard._stat-card', ['label' => 'Overdue', 'value' => $counts['overdue'], 'icon' => 'bi-alarm', 'color' => 'danger', 'link' => route('tickets.index', ['overdue' => 1])])
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Recently updated assigned tickets</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-card">
                    <thead><tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Due</th></tr></thead>
                    <tbody>
                        @forelse ($recentTickets as $ticket)
                            <tr class="{{ $ticket->isOverdue() ? 'row-overdue' : '' }}">
                                <td class="text-nowrap"><a href="{{ route('tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_number }}</a></td>
                                <td>
                                    <div class="ticket-subject text-truncate">{{ $ticket->subject }}</div>
                                    <div class="small text-muted">{{ $ticket->category->name }} · {{ $ticket->creator->name }}</div>
                                </td>
                                <td class="text-nowrap">@include('partials.ticket-badges', ['ticket' => $ticket])</td>
                                <td class="text-nowrap small">{{ $ticket->due_at->format('d M, h:i A') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted text-center py-4">No tickets are assigned to you yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header text-danger"><i class="bi bi-alarm me-1"></i> My overdue tickets</div>
            <ul class="list-group list-group-flush">
                @forelse ($overdueTickets as $ticket)
                    <li class="list-group-item">
                        <a href="{{ route('tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_number }}</a>
                        <span class="text-truncate d-block">{{ $ticket->subject }}</span>
                        <span class="small text-danger">Due {{ $ticket->due_at->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Great job — nothing is overdue.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
