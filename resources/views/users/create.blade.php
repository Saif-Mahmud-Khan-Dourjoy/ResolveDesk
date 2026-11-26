@extends('layouts.app')

@section('title', 'Create User')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
    <li class="breadcrumb-item active" aria-current="page">Create</li>
@endsection

@section('content')
<div class="page-header">
    <h1 class="page-title">Create User</h1>
</div>

<div class="row">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('users.store') }}">
                    @csrf
                    @include('users._form')
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Create User</button>
                        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
