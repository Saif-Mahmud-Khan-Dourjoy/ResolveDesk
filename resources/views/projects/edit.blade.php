@extends('layouts.app')

@section('title', 'Edit ' . $project->code)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('projects.index') }}">Projects</a></li>
    <li class="breadcrumb-item"><a href="{{ route('projects.show', $project) }}">{{ $project->code }}</a></li>
    <li class="breadcrumb-item active" aria-current="page">Edit</li>
@endsection

@section('content')
<div class="page-header">
    <h1 class="page-title">Edit Project {{ $project->code }}</h1>
</div>

<div class="row">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('projects.update', $project) }}" data-disable-on-submit>
                    @csrf
                    @method('PUT')
                    @include('projects._form')
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                        <a href="{{ route('projects.show', $project) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
