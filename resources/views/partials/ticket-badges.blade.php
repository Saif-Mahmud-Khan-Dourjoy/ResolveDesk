{{--
    Status + priority (+ overdue) badges for one ticket.
    Usage: @include('partials.ticket-badges', ['ticket' => $ticket])
    Optional: 'only' => 'status' | 'priority'
    Colors are defined in public/css/app.css (.badge-status-*, .badge-priority-*).
--}}
@php($only = $only ?? null)

@if ($only === null || $only === 'status')
    <span class="badge badge-status-{{ $ticket->status }}">{{ $ticket->statusLabel() }}</span>
@endif

@if ($only === null || $only === 'priority')
    <span class="badge badge-priority-{{ $ticket->priority }}">{{ $ticket->priorityLabel() }}</span>
@endif

@if ($only === null && $ticket->isOverdue())
    <span class="badge badge-overdue"><i class="bi bi-alarm"></i> Overdue</span>
@endif
