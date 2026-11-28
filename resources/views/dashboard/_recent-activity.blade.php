{{--
    V2: latest audit-trail entries. The controller already limits $activities to
    tickets this user may see (admins: all, agents: created by / assigned to them).
--}}
<div class="card mt-3">
    <div class="card-header">Recent activity</div>
    <ul class="list-group list-group-flush small">
        @forelse ($activities as $activity)
            <li class="list-group-item d-flex justify-content-between gap-2">
                <span>
                    <span class="fw-semibold">{{ $activity->isSystem() ? 'System' : $activity->user->name }}</span>
                    {{ strtolower($activity->actionLabel()) }}
                    <a href="{{ route('tickets.show', $activity->ticket_id) }}" class="text-decoration-none">{{ $activity->ticket->ticket_number }}</a>
                    @if ($activity->is_internal)<span class="badge bg-warning-subtle text-warning-emphasis">internal</span>@endif
                </span>
                <span class="text-muted text-nowrap">{{ $activity->created_at->diffForHumans() }}</span>
            </li>
        @empty
            <li class="list-group-item text-muted">No activity yet.</li>
        @endforelse
    </ul>
</div>
