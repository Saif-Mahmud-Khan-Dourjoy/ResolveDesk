@extends('layouts.app')

@section('title', 'Notifications')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Notifications</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Notifications</h1>
        <p class="page-subtitle">Your complete notification history.</p>
    </div>
    <form method="POST" action="{{ route('notifications.read-all') }}">
        @csrf
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-check2-all me-1"></i> Mark all as read</button>
    </form>
</div>

{{-- Read / unread filter --}}
<ul class="nav nav-pills mb-3">
    @foreach (['' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $value => $label)
        <li class="nav-item">
            <a class="nav-link {{ request('filter', '') === $value ? 'active' : '' }}"
               href="{{ route('notifications.index', $value ? ['filter' => $value] : []) }}">{{ $label }}</a>
        </li>
    @endforeach
</ul>

<div class="card">
    <ul class="list-group list-group-flush">
        @forelse ($notifications as $notification)
            <li class="list-group-item py-3 {{ $notification->read_at ? '' : 'bg-primary-subtle bg-opacity-25' }}">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div class="min-w-0">
                        <div class="fw-semibold">
                            @unless ($notification->read_at)
                                <span class="badge bg-primary me-1">New</span>
                            @endunless
                            {{ $notification->data['ticket_number'] ?? '' }} · {{ $notification->data['title'] ?? 'Notification' }}
                        </div>
                        <div class="text-muted">{{ $notification->data['message'] ?? '' }}</div>
                        <div class="small text-muted mt-1" title="{{ $notification->created_at->format('d M Y, h:i A') }}">
                            {{ $notification->created_at->diffForHumans() }}
                        </div>
                    </div>
                    <div class="d-flex gap-2 align-items-start">
                        {{-- Opening marks it as read (POST), then redirects to the ticket. --}}
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                            @csrf
                            <input type="hidden" name="open_ticket" value="1">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Open ticket</button>
                        </form>
                        @unless ($notification->read_at)
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Mark as read</button>
                            </form>
                        @endunless
                    </div>
                </div>
            </li>
        @empty
            <li class="list-group-item text-center text-muted py-5">
                <i class="bi bi-bell-slash fs-3 d-block mb-2"></i> No notifications here.
            </li>
        @endforelse
    </ul>
    @if ($notifications->hasPages())
        <div class="card-footer bg-white">{{ $notifications->links() }}</div>
    @endif
</div>
@endsection
