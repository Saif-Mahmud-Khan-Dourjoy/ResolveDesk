@extends('layouts.app')

@section('title', 'Edit ' . $client->code)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('clients.index') }}">Clients</a></li>
    <li class="breadcrumb-item"><a href="{{ route('clients.show', $client) }}">{{ $client->code }}</a></li>
    <li class="breadcrumb-item active" aria-current="page">Edit</li>
@endsection

@section('content')
<div class="page-header">
    <h1 class="page-title">Edit Client {{ $client->code }}</h1>
</div>

<div class="row">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('clients.update', $client) }}" data-disable-on-submit>
                    @csrf
                    @method('PUT')
                    @include('clients._form')
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                        <a href="{{ route('clients.show', $client) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
