@extends('layouts.app')

@section('title', $ticket->ticket_number)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('tickets.index') }}">Tickets</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $ticket->ticket_number }}</li>
@endsection

@section('content')
<div class="page-header">
    <div class="min-w-0">
        <div class="text-muted small fw-semibold">
            {{ $ticket->ticket_number }} ·
            {{ $ticket->project ? $ticket->project->code : 'Global' }}
            @if ($ticket->client) · {{ $ticket->client->code }} @endif
        </div>
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
                @include('tickets._attachments', ['attachments' => $ticket->attachments])
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

        {{-- Conversation. For customers the controller already removed internal notes in SQL. --}}
        <div class="card mb-4" id="conversation">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-chat-left-text me-1"></i> Conversation</span>
                <span class="text-muted small">{{ $ticket->comments->count() }} message(s)</span>
            </div>
            <div class="card-body">
                @forelse ($ticket->comments as $comment)
                    <div class="comment {{ $comment->isInternal() ? 'comment-internal' : '' }}">
                        <span class="avatar">{{ strtoupper(substr($comment->user->name, 0, 1)) }}</span>
                        <div class="comment-body">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-1">
                                <div>
                                    <span class="fw-semibold">{{ $comment->user->name }}</span>
                                    <span class="badge text-bg-light border ms-1">{{ $comment->user->roleLabel() }}</span>
                                    @if ($comment->isInternal())
                                        <span class="badge bg-warning-subtle text-warning-emphasis ms-1"><i class="bi bi-eye-slash"></i> Internal note</span>
                                    @endif
                                </div>
                                <span class="small text-muted">{{ $comment->created_at->format('d M Y, h:i A') }}</span>
                            </div>
                            <div class="text-prewrap">{{ $comment->message }}</div>
                            @include('tickets._attachments', ['attachments' => $comment->attachments])
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">No comments yet.</p>
                @endforelse
            </div>

            <div class="card-footer bg-white">
                @can('comment', $ticket)
                    <form method="POST" action="{{ route('tickets.comments.store', $ticket) }}" enctype="multipart/form-data" data-disable-on-submit>
                        @csrf
                        <label for="message" class="form-label required">Add a message</label>
                        <textarea id="message" name="message" rows="3" class="form-control @error('message') is-invalid @enderror"
                                  placeholder="Write a reply..." required>{{ old('message') }}</textarea>
                        @error('message')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror

                        <div class="row g-2 mt-1 align-items-end">
                            @can('addInternalNote', $ticket)
                                <div class="col-md-5">
                                    <label for="visibility" class="form-label small mb-1">Visibility</label>
                                    <select id="visibility" name="visibility" class="form-select form-select-sm">
                                        <option value="public" @selected(old('visibility') !== 'internal')>Public reply (customer can see)</option>
                                        <option value="internal" @selected(old('visibility') === 'internal')>Internal note (staff only)</option>
                                    </select>
                                </div>
                            @endcan
                            <div class="col-md">
                                @include('tickets._attachment-input')
                            </div>
                            <div class="col-md-auto text-end">
                                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i> Post</button>
                            </div>
                        </div>
                    </form>
                @else
                    <p class="text-muted small mb-0"><i class="bi bi-lock me-1"></i> This ticket is closed and read-only.</p>
                @endcan
            </div>
        </div>

        {{-- Activity timeline (internal entries are filtered out for customers) --}}
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> Activity History</div>
            <div class="card-body">
                <ul class="timeline">
                    @foreach ($ticket->activities as $activity)
                        <li class="timeline-item">
                            <div>
                                @if ($activity->isSystem())
                                    <span class="fw-semibold"><i class="bi bi-cpu"></i> System</span>
                                @else
                                    <span class="fw-semibold">{{ $activity->user->name }}</span>
                                @endif
                                <span class="text-muted">{{ strtolower($activity->actionLabel()) }}</span>
                                @if ($activity->is_internal)
                                    <span class="badge bg-warning-subtle text-warning-emphasis">internal</span>
                                @endif
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
             The controller checks the SAME policy again, so hidden buttons cannot be forced.
             Every form sends the ticket "version" the user is looking at (optimistic locking). --}}
        @php($hasActions = auth()->user()->can('assign', $ticket) || auth()->user()->can('start', $ticket) || auth()->user()->can('resolve', $ticket) || auth()->user()->can('reopen', $ticket) || auth()->user()->can('close', $ticket))

        @if ($hasActions)
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-lightning-charge me-1"></i> Actions</div>
                <div class="card-body d-grid gap-3">

                    @can('assign', $ticket)
                        <form method="POST" action="{{ route('tickets.assign', $ticket) }}" data-disable-on-submit
                              data-confirm="{{ $ticket->assigned_to ? 'Reassign this ticket to the selected agent?' : 'Assign this ticket to the selected agent?' }}">
                            @csrf
                            <input type="hidden" name="version" value="{{ $ticket->version }}">
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
                            <div class="form-text">
                                {{ $ticket->project ? 'Only active agents of ' . $ticket->project->code . ' are listed.' : 'Global ticket: every active agent is eligible.' }}
                            </div>
                            @error('agent_id')<div class="text-danger small">{{ $message }}</div>@enderror
                        </form>

                        @if ($ticket->status === \App\Models\Ticket::STATUS_OPEN)
                            <form method="POST" action="{{ route('tickets.auto-assign', $ticket) }}" data-disable-on-submit
                                  data-confirm="Automatically assign this ticket to the eligible agent with the lowest workload?">
                                @csrf
                                <input type="hidden" name="version" value="{{ $ticket->version }}">
                                <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-shuffle me-1"></i> Auto Assign</button>
                                <div class="form-text">Picks the eligible agent with the fewest Assigned/In Progress tickets.</div>
                            </form>
                        @endif
                    @endcan

                    @can('start', $ticket)
                        <form method="POST" action="{{ route('tickets.start', $ticket) }}" data-confirm="Start working on this ticket?" data-disable-on-submit>
                            @csrf
                            <input type="hidden" name="version" value="{{ $ticket->version }}">
                            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-play-circle me-1"></i> Start Working</button>
                        </form>
                    @endcan

                    @can('resolve', $ticket)
                        <form method="POST" action="{{ route('tickets.resolve', $ticket) }}" data-confirm="Mark this ticket as resolved?" data-disable-on-submit>
                            @csrf
                            <input type="hidden" name="version" value="{{ $ticket->version }}">
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
                        <form method="POST" action="{{ route('tickets.close', $ticket) }}" data-disable-on-submit
                              data-confirm="Confirm the resolution and close this ticket? Closed tickets cannot be changed.">
                            @csrf
                            <input type="hidden" name="version" value="{{ $ticket->version }}">
                            <button type="submit" class="btn btn-success w-100"><i class="bi bi-lock me-1"></i> Confirm &amp; Close Ticket</button>
                        </form>
                    @endcan

                    @can('reopen', $ticket)
                        <form method="POST" action="{{ route('tickets.reopen', $ticket) }}" data-disable-on-submit
                              data-confirm="Reopen this ticket? It will go back to In Progress.">
                            @csrf
                            <input type="hidden" name="version" value="{{ $ticket->version }}">
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

                    <dt>Project</dt>
                    <dd>
                        @if ($ticket->project)
                            {{ $ticket->project->displayName() }}
                            @unless ($ticket->project->is_active)<span class="badge text-bg-light border">Inactive</span>@endunless
                        @else
                            <span class="text-muted">Global (no specific project)</span>
                        @endif
                    </dd>

                    <dt>Client</dt>
                    <dd>{{ $ticket->client?->displayName() ?? 'Internal (no client)' }}</dd>

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
                            <div class="small text-muted">Remaining: {{ $ticket->slaRemainingForHumans() }}</div>
                        @elseif ($ticket->resolvedWithinSla() === true)
                            <div><span class="badge bg-success-subtle text-success-emphasis mt-1">Resolved within SLA</span></div>
                        @elseif ($ticket->resolvedWithinSla() === false)
                            <div><span class="badge bg-danger-subtle text-danger-emphasis mt-1">Resolved after SLA</span></div>
                        @endif
                    </dd>

                    <dt>Created</dt>
                    <dd>{{ $ticket->created_at->format('d M Y, h:i A') }}</dd>

                    <dt>Resolved</dt>
                    <dd>{{ $ticket->resolved_at ? $ticket->resolved_at->format('d M Y, h:i A') : '—' }}</dd>

                    <dt>Version</dt>
                    <dd class="mb-0 small text-muted" title="Optimistic locking counter: increases with every change">v{{ $ticket->version }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
