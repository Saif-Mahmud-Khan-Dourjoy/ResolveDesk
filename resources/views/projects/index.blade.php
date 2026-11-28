@extends('layouts.app')

@section('title', 'Projects')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Projects</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Projects</h1>
        <p class="page-subtitle">Software products we support. Only active projects can be chosen for new tickets.</p>
    </div>
    <a href="{{ route('projects.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Create Project</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('projects.index') }}" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label for="search" class="form-label small mb-1">Search</label>
                <input type="text" id="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Name or code">
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
                <a href="{{ route('projects.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead>
                <tr><th>Code</th><th>Name</th><th class="text-center">Clients</th><th class="text-center">Agents</th><th class="text-center">Tickets</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                @forelse ($projects as $project)
                    <tr>
                        <td class="fw-semibold"><a href="{{ route('projects.show', $project) }}" class="text-decoration-none">{{ $project->code }}</a></td>
                        <td>{{ $project->name }}</td>
                        <td class="text-center">{{ $project->clients_count }}</td>
                        <td class="text-center">{{ $project->agents_count }}</td>
                        <td class="text-center">{{ $project->tickets_count }}</td>
                        <td>@include('partials.active-badge', ['active' => $project->is_active])</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-outline-primary">View</a>
                            <a href="{{ route('projects.edit', $project) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No projects found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($projects->hasPages())
        <div class="card-footer bg-white">{{ $projects->links() }}</div>
    @endif
</div>
@endsection
