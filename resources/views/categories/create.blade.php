@extends('layouts.app')

@section('title', 'Create Category')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('categories.index') }}">Categories</a></li>
    <li class="breadcrumb-item active" aria-current="page">Create</li>
@endsection

@section('content')
<div class="page-header">
    <h1 class="page-title">Create Category</h1>
</div>

<div class="row">
    <div class="col-xl-6">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('categories.store') }}">
                    @csrf
                    @include('categories._form')
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Create Category</button>
                        <a href="{{ route('categories.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
