@extends('layouts.app')

@section('title', $ticket->ticket_number)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('tickets.index') }}">Tickets</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $ticket->ticket_number }}</li>
@endsection

@section('content')
<div class="page-header">
    <div class="min-w-0">
        <div class="text-muted small fw-semibold">{{ $ticket->ticket_number }}</div>
        <h1 class="page-title text-break">{{ $ticket->subject }}</h1>
        <div class="mt-2 d-flex flex-wrap gap-1">@include('partials.ticket-badges', ['ticket' => $ticket])</div>
    </div>
    @can('update', $ticket)
        <a href="{{ route('tickets.edit', $ticket) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i> Edit Ticket</a>
    @endcan
</div>

<div class="row g-4">
    {{-- ================= Main column ================= --}}
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">Description</div>
            <div class="card-body">
                {{-- {{ }} escapes HTML (XSS protection). CSS keeps the line breaks. --}}
                <div class="text-prewrap">{{ $ticket->description }}</div>
            </div>
        </div>

        @if ($ticket->resolution_note)
            <div class="card mb-4 border-success-subtle">
                <div class="card-header text-success"><i class="bi bi-check-circle me-1"></i> Resolution Note</div>
                <div class="card-body">
                    <div class="text-prewrap">{{ $ticket->resolution_note }}</div>
                    @if ($ticket->resolved_at)
                        <div class="small text-muted mt-2">Resolved {{ $ticket->resolved_at->format('d M Y, h:i A') }}</div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Conversation --}}
        <div class="card mb-4" id="conversation">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-chat-left-text me-1"></i> Conversation</span>
                <span class="text-muted small">{{ $ticket->comments->count() }} comment(s)</span>
            </div>
            <div class="card-body">
                @forelse ($ticket->comments as $comment)
                    <div class="comment">
                        <span class="avatar">{{ strtoupper(substr($comment->user->name, 0, 1)) }}</span>
                        <div class="comment-body">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-1">
                                <div>
                                    <span class="fw-semibold">{{ $comment->user->name }}</span>
                                    <span class="badge text-bg-light border ms-1">{{ $comment->user->roleLabel() }}</span>
                                </div>
                                <span class="small text-muted">{{ $comment->created_at->format('d M Y, h:i A') }}</span>
                            </div>
                            <div class="text-prewrap">{{ $comment->message }}</div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">No comments yet.</p>
                @endforelse
            </div>

            <div class="card-footer bg-white">
                @can('comment', $ticket)
                    <form method="POST" action="{{ route('tickets.comments.store', $ticket) }}">
                        @csrf
                        <label for="message" class="form-label required">Add a comment</label>
                        <textarea id="message" name="message" rows="3" class="form-control @error('message') is-invalid @enderror"
                                  placeholder="Write a reply..." required>{{ old('message') }}</textarea>
                        @error('message')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="mt-2 text-end">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i> Post Comment</button>
                        </div>
                    </form>
                @else
                    <p class="text-muted small mb-0"><i class="bi bi-lock me-1"></i> This ticket is closed and read-only.</p>
                @endcan
            </div>
        </div>

        {{-- Activity timeline --}}
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> Activity History</div>
            <div class="card-body">
                <ul class="timeline">
                    @foreach ($ticket->activities as $activity)
                        <li class="timeline-item">
                            <div>
                                <span class="fw-semibold">{{ $activity->user->name }}</span>
                                <span class="text-muted">{{ strtolower($activity->actionLabel()) }}</span>
                            </div>
                            @if ($activity->old_value !== null || $activity->new_value !== null)
                                <div class="small text-prewrap">
                                    @if ($activity->old_value !== null)
                                        <span class="text-muted text-decoration-line-through">{{ $activity->old_value }}</span>
                                        <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                    @endif
                                    <span>{{ $activity->new_value }}</span>
                                </div>
                            @endif
                            <div class="small text-muted">{{ $activity->created_at->format('d M Y, h:i A') }}</div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    {{-- ================= Side panel ================= --}}
    <div class="col-lg-4">
        {{-- Workflow actions: @can() uses TicketPolicy, so invalid actions are not shown.
             The controller checks the SAME policy again, so hidden buttons cannot be forced. --}}
        @php($hasActions = auth()->user()->can('assign', $ticket) || auth()->user()->can('start', $ticket) || auth()->user()->can('resolve', $ticket) || auth()->user()->can('reopen', $ticket) || auth()->user()->can('close', $ticket))

        @if ($hasActions)
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-lightning-charge me-1"></i> Actions</div>
                <div class="card-body d-grid gap-3">

                    @can('assign', $ticket)
                        <form method="POST" action="{{ route('tickets.assign', $ticket) }}"
                              data-confirm="{{ $ticket->assigned_to ? 'Reassign this ticket to the selected agent?' : 'Assign this ticket to the selected agent?' }}">
                            @csrf
                            <label for="agent_id" class="form-label small fw-semibold">
                                {{ $ticket->assigned_to ? 'Reassign Agent' : 'Assign Agent' }}
                            </label>
                            <div class="input-group">
                                <select id="agent_id" name="agent_id" class="form-select" required>
                                    <option value="">Select an agent</option>
                                    @foreach ($agents as $agent)
                                        <option value="{{ $agent->id }}" @selected($ticket->assigned_to === $agent->id)>
                                            {{ $agent->name }} ({{ $agent->workload_count }} active)
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-primary">{{ $ticket->assigned_to ? 'Reassign' : 'Assign' }}</button>
                            </div>
                        </form>

                        @if ($ticket->status === \App\Models\Ticket::STATUS_OPEN)
                            <form method="POST" action="{{ route('tickets.auto-assign', $ticket) }}"
                                  data-confirm="Automatically assign this ticket to the agent with the lowest workload?">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-shuffle me-1"></i> Auto Assign</button>
                                <div class="form-text">Picks the active agent with the fewest Assigned/In Progress tickets.</div>
                            </form>
                        @endif
                    @endcan

                    @can('start', $ticket)
                        <form method="POST" action="{{ route('tickets.start', $ticket) }}" data-confirm="Start working on this ticket?">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-play-circle me-1"></i> Start Working</button>
                        </form>
                    @endcan

                    @can('resolve', $ticket)
                        <form method="POST" action="{{ route('tickets.resolve', $ticket) }}" data-confirm="Mark this ticket as resolved?">
                            @csrf
                            <label for="resolution_note" class="form-label small fw-semibold required">Resolution note</label>
                            <textarea id="resolution_note" name="resolution_note" rows="3"
                                      class="form-control mb-2 @error('resolution_note') is-invalid @enderror"
                                      placeholder="Explain what was done to fix the issue." required>{{ old('resolution_note') }}</textarea>
                            @error('resolution_note')
                                <div class="invalid-feedback mb-2">{{ $message }}</div>
                            @enderror
                            <button type="submit" class="btn btn-success w-100"><i class="bi bi-check2-circle me-1"></i> Resolve Ticket</button>
                        </form>
                    @endcan

                    @can('close', $ticket)
                        <form method="POST" action="{{ route('tickets.close', $ticket) }}" data-confirm="Confirm the resolution and close this ticket? Closed tickets cannot be changed.">
                            @csrf
                            <button type="submit" class="btn btn-success w-100"><i class="bi bi-lock me-1"></i> Confirm &amp; Close Ticket</button>
                        </form>
                    @endcan

                    @can('reopen', $ticket)
                        <form method="POST" action="{{ route('tickets.reopen', $ticket) }}" data-confirm="Reopen this ticket? It will go back to In Progress.">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-arrow-counterclockwise me-1"></i> Reopen Ticket</button>
                        </form>
                    @endcan
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-1"></i> Ticket Details</div>
            <div class="card-body">
                <dl class="detail-list mb-0">
                    <dt>Ticket number</dt>
                    <dd class="fw-semibold">{{ $ticket->ticket_number }}</dd>

                    <dt>Category</dt>
                    <dd>
                        {{ $ticket->category->name }}
                        @if (! $ticket->category->is_active)
                            <span class="badge text-bg-light border">Inactive</span>
                        @endif
                    </dd>

                    <dt>Priority</dt>
                    <dd>@include('partials.ticket-badges', ['ticket' => $ticket, 'only' => 'priority'])</dd>

                    <dt>Status</dt>
                    <dd>@include('partials.ticket-badges', ['ticket' => $ticket, 'only' => 'status'])</dd>

                    <dt>Created by</dt>
                    <dd>{{ $ticket->creator->name }} <span class="text-muted small">({{ $ticket->creator->roleLabel() }})</span></dd>

                    <dt>Assigned agent</dt>
                    <dd>{{ $ticket->assignedAgent?->name ?? 'Not assigned yet' }}</dd>

                    <dt>SLA deadline</dt>
                    <dd>
                        <span class="{{ $ticket->isOverdue() ? 'text-danger fw-semibold' : '' }}">{{ $ticket->due_at->format('d M Y, h:i A') }}</span>
                        @if ($ticket->isOverdue())
                            <div><span class="badge badge-overdue mt-1"><i class="bi bi-alarm"></i> Overdue by {{ $ticket->due_at->diffForHumans(null, true) }}</span></div>
                        @elseif (in_array($ticket->status, \App\Models\Ticket::ACTIVE_STATUSES, true))
                            <div class="small text-muted">{{ $ticket->due_at->diffForHumans() }}</div>
                        @endif
                    </dd>

                    <dt>Created</dt>
                    <dd>{{ $ticket->created_at->format('d M Y, h:i A') }}</dd>

                    <dt>Resolved</dt>
                    <dd class="mb-0">{{ $ticket->resolved_at ? $ticket->resolved_at->format('d M Y, h:i A') : '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
