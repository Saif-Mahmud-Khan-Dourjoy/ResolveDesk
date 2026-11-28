@extends('layouts.app')

@section('title', 'Edit ' . $ticket->ticket_number)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('tickets.index') }}">Tickets</a></li>
    <li class="breadcrumb-item"><a href="{{ route('tickets.show', $ticket) }}">{{ $ticket->ticket_number }}</a></li>
    <li class="breadcrumb-item active" aria-current="page">Edit</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Edit Ticket {{ $ticket->ticket_number }}</h1>
        <p class="page-subtitle">Status is changed with the workflow buttons on the ticket page, not here.</p>
    </div>
</div>

<div class="row">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                {{-- HTML forms only support GET/POST; @method('PUT') tells Laravel to treat it as PUT. --}}
                <form method="POST" action="{{ route('tickets.update', $ticket) }}" data-disable-on-submit>
                    @csrf
                    @method('PUT')
                    {{-- Optimistic locking: the version this form was based on. If someone else changes
                         the ticket first, saving fails with a conflict instead of overwriting their work.
                         Always the CURRENT version (never old('version')): after a conflict the form is
                         shown again with the user's input and a warning; saving again is then a
                         deliberate decision. Re-sending the stale version would fail forever. --}}
                    <input type="hidden" name="version" value="{{ $ticket->version }}">

                    <div class="alert alert-light border small">
                        <i class="bi bi-diagram-3 me-1"></i>
                        Scope (cannot be changed): <strong>{{ $ticket->project?->displayName() ?? 'Global' }}</strong>
                        · {{ $ticket->client?->name ?? 'Internal (no client)' }}
                    </div>

                    @include('tickets._form', ['ticket' => $ticket])

                    @if (! $ticket->category->is_active)
                        <div class="alert alert-warning small">
                            The current category "{{ $ticket->category->name }}" is inactive. You may keep it or choose an active category.
                        </div>
                    @endif

                    <div class="d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                        <a href="{{ route('tickets.show', $ticket) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
