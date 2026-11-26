@extends('layouts.app')

@section('title', 'Create Ticket')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('tickets.index') }}">Tickets</a></li>
    <li class="breadcrumb-item active" aria-current="page">Create</li>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Create Ticket</h1>
        <p class="page-subtitle">Tell us about the issue. Our support team will be notified.</p>
    </div>
</div>

<div class="row">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('tickets.store') }}">
                    @csrf
                    @include('tickets._form')

                    <div class="d-flex gap-2 mt-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Submit Ticket</button>
                        <a href="{{ route('tickets.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
