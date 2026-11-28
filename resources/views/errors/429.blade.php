{{-- Laravel shows this view for HTTP 429 (Too Many Requests), raised by the throttle:* middleware. --}}
@extends('layouts.guest')

@section('title', 'Too many requests')

@section('content')
<div class="auth-wrapper">
    <div class="card auth-card text-center">
        <div class="card-body p-5">
            <div class="display-6 text-warning mb-2"><i class="bi bi-hourglass-split"></i></div>
            <h1 class="h4 fw-bold">Please slow down</h1>
            <p class="text-muted">You sent too many requests in a short time. Please wait a minute and try again.</p>
            <a href="{{ url()->previous() ?: url('/dashboard') }}" class="btn btn-primary">Go back</a>
        </div>
    </div>
</div>
@endsection
