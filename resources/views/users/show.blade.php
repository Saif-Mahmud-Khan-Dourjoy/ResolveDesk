@extends('layouts.app')

@section('title', $user->name)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $user->name }}</li>
@endsection

@section('content')
<div class="page-header">
    <div class="d-flex align-items-center gap-3">
        <span class="avatar" style="width: 52px; height: 52px; font-size: 1.2rem;">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
        <div>
            <h1 class="page-title">{{ $user->name }}</h1>
            <p class="page-subtitle">{{ $user->email }}</p>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('users.edit', $user) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i> Edit</a>
        @if ($user->id !== auth()->id())
            <form method="POST" action="{{ route('users.toggle-active', $user) }}" data-confirm="{{ $user->is_active ? 'Deactivate' : 'Activate' }} this user?">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                    {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                </button>
            </form>
        @endif
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Role</div>
            <div class="fw-semibold fs-5">{{ $user->roleLabel() }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Status</div>
            <div class="fw-semibold fs-5 {{ $user->is_active ? 'text-success' : 'text-danger' }}">{{ $user->is_active ? 'Active' : 'Inactive' }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Tickets created</div>
            <div class="stat-value">{{ $createdCount }}</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="stat-label">Current workload (Assigned + In Progress)</div>
            <div class="stat-value">{{ $assignedActiveCount }}</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Recent tickets created by or assigned to this user</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead><tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Created by</th><th>Agent</th></tr></thead>
            <tbody>
                @forelse ($recentTickets as $ticket)
                    <tr>
                        <td><a href="{{ route('tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_number }}</a></td>
                        <td><div class="ticket-subject text-truncate">{{ $ticket->subject }}</div></td>
                        <td class="text-nowrap">@include('partials.ticket-badges', ['ticket' => $ticket])</td>
                        <td>{{ $ticket->creator->name }}</td>
                        <td>{{ $ticket->assignedAgent?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted text-center py-4">No tickets.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="small text-muted mt-3">Member since {{ $user->created_at->format('d M Y') }}. Users are never deleted because tickets and history reference them; deactivate them instead.</div>
@endsection
