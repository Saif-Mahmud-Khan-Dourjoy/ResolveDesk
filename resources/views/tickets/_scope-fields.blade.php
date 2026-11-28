{{--
    V2 ticket scope (create form only - scope cannot change after creation).

    Admin:    Client (optional) + Project (optional). Changing the client reloads the
              project list through Fetch (public/js/ticket-form.js -> tickets.project-options).
    Customer: organization is fixed (from the account); project list = that organization's projects.
    Agent:    internal ticket; project list = projects the agent supports.

    The lists are only a convenience. TicketService::resolveScope() re-checks everything.
--}}
@php($currentUser = auth()->user())

<div class="border rounded p-3 mb-3 bg-light-subtle">
    <div class="fw-semibold mb-2"><i class="bi bi-diagram-3 me-1"></i> Ticket scope</div>

    <div class="row">
        @if ($currentUser->isAdmin())
            <div class="col-md-6 mb-2">
                <label for="client_id" class="form-label small">Client organization</label>
                <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror"
                        data-project-options-url="{{ route('tickets.project-options') }}">
                    <option value="">Internal ticket (no client)</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->code }} - {{ $client->name }}</option>
                    @endforeach
                </select>
                @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @elseif ($currentUser->isCustomer())
            <div class="col-md-6 mb-2">
                <div class="form-label small">Organization</div>
                <div class="form-control-plaintext py-1">
                    {{ $currentUser->client?->name ?? 'Standalone customer (no organization)' }}
                </div>
            </div>
        @else
            <div class="col-md-6 mb-2">
                <div class="form-label small">Client</div>
                <div class="form-control-plaintext py-1">Internal ticket (agents cannot create tickets for clients)</div>
            </div>
        @endif

        <div class="col-md-6 mb-2">
            <label for="project_id" class="form-label small">Project</label>
            <select id="project_id" name="project_id" class="form-select @error('project_id') is-invalid @enderror"
                    @disabled($currentUser->isCustomer() && $currentUser->client_id === null)>
                <option value="">Global - not about a specific project</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>{{ $project->displayName() }}</option>
                @endforeach
            </select>
            @error('project_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text" id="project_help">
                @if ($currentUser->isCustomer() && $currentUser->client_id === null)
                    Standalone customers can only create global tickets.
                @else
                    A global ticket is still private: only authorized users can see it.
                @endif
            </div>
        </div>
    </div>
</div>
