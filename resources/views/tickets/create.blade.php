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
                {{-- enctype is required for file uploads. data-disable-on-submit stops double clicks in
                     the browser; the hidden request_token stops duplicates on the SERVER. --}}
                <form method="POST" action="{{ route('tickets.store') }}" enctype="multipart/form-data" data-disable-on-submit>
                    @csrf
                    <input type="hidden" name="request_token" value="{{ $requestToken }}">
                    @error('request_token')<div class="alert alert-danger small">{{ $message }}</div>@enderror

                    @include('tickets._scope-fields')
                    @include('tickets._form')

                    <div class="mb-3">
                        @include('tickets._attachment-input')
                    </div>

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

@push('scripts')
    <script src="{{ asset('js/ticket-form.js') }}"></script>
@endpush
