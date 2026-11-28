@extends('layouts.app')

@section('title', 'Users')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Users</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Users</h1>
        <p class="page-subtitle">Administrators, support agents and customers.</p>
    </div>
    <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i> Create User</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('users.index') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label small mb-1">Search</label>
                <input type="text" id="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Name or email">
            </div>
            <div class="col-6 col-md-2">
                <label for="role" class="form-label small mb-1">Role</label>
                <select id="role" name="role" class="form-select">
                    <option value="">All roles</option>
                    @foreach (\App\Models\User::ROLES as $value => $label)
                        <option value="{{ $value }}" @selected(request('role') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="status" class="form-label small mb-1">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="client_id" class="form-label small mb-1">Client</label>
                <select id="client_id" name="client_id" class="form-select">
                    <option value="">All</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((string) request('client_id') === (string) $client->id)>{{ $client->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Client</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td class="fw-semibold"><a href="{{ route('users.show', $user) }}" class="text-decoration-none">{{ $user->name }}</a></td>
                        <td>{{ $user->email }}</td>
                        <td><span class="badge text-bg-light border">{{ $user->roleLabel() }}</span></td>
                        <td class="small">{{ $user->client?->code ?? '—' }}</td>
                        <td>
                            @if ($user->is_active)
                                <span class="badge bg-success-subtle text-success-emphasis">Active</span>
                            @else
                                <span class="badge bg-danger-subtle text-danger-emphasis">Inactive</span>
                            @endif
                        </td>
                        <td class="small text-nowrap">{{ $user->created_at->format('d M Y') }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('users.show', $user) }}" class="btn btn-sm btn-outline-primary">View</a>
                            <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            @if ($user->id !== auth()->id())
                                <form method="POST" action="{{ route('users.toggle-active', $user) }}" class="d-inline"
                                      data-confirm="{{ $user->is_active ? 'Deactivate' : 'Activate' }} {{ $user->name }}?">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                        {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($users->hasPages())
        <div class="card-footer bg-white">{{ $users->links() }}</div>
    @endif
</div>
@endsection
