@extends('layouts.app')

@section('title', 'Clients')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Clients</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Client Organizations</h1>
        <p class="page-subtitle">Companies using our projects. Customers of an inactive client cannot create new tickets.</p>
    </div>
    <a href="{{ route('clients.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Create Client</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('clients.index') }}" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label for="search" class="form-label small mb-1">Search</label>
                <input type="text" id="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Name, code or email">
            </div>
            <div class="col-6 col-md-3">
                <label for="status" class="form-label small mb-1">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">All</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Filter</button>
                <a href="{{ route('clients.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead>
                <tr><th>Code</th><th>Name</th><th>Email</th><th class="text-center">Projects</th><th class="text-center">Customers</th><th class="text-center">Tickets</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr>
                        <td class="fw-semibold"><a href="{{ route('clients.show', $client) }}" class="text-decoration-none">{{ $client->code }}</a></td>
                        <td>{{ $client->name }}</td>
                        <td class="small text-muted">{{ $client->email ?: '—' }}</td>
                        <td class="text-center">{{ $client->projects_count }}</td>
                        <td class="text-center">{{ $client->customers_count }}</td>
                        <td class="text-center">{{ $client->tickets_count }}</td>
                        <td>@include('partials.active-badge', ['active' => $client->is_active])</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('clients.show', $client) }}" class="btn btn-sm btn-outline-primary">View</a>
                            <a href="{{ route('clients.edit', $client) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No clients found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($clients->hasPages())
        <div class="card-footer bg-white">{{ $clients->links() }}</div>
    @endif
</div>
@endsection
