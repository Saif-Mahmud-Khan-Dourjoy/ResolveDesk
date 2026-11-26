@extends('layouts.app')

@section('title', 'Categories')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Categories</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Ticket Categories</h1>
        <p class="page-subtitle">Only active categories appear in the ticket form. Categories in use cannot be deleted.</p>
    </div>
    <a href="{{ route('categories.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Create Category</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead>
                <tr><th>Name</th><th>Description</th><th class="text-center">Tickets</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $category->name }}</td>
                        <td class="text-muted small">{{ $category->description ?: '—' }}</td>
                        <td class="text-center">{{ $category->tickets_count }}</td>
                        <td>
                            @if ($category->is_active)
                                <span class="badge bg-success-subtle text-success-emphasis">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('categories.edit', $category) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            @if ($category->tickets_count === 0)
                                <form method="POST" action="{{ route('categories.destroy', $category) }}" class="d-inline"
                                      data-confirm="Delete the category &quot;{{ $category->name }}&quot;? This cannot be undone.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            @else
                                <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Used by tickets - deactivate it instead">Delete</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No categories yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($categories->hasPages())
        <div class="card-footer bg-white">{{ $categories->links() }}</div>
    @endif
</div>
@endsection
