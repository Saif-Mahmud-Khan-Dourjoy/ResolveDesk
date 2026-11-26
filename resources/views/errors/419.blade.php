{{-- HTTP 419 = the CSRF token is missing or the session expired (e.g. a form left open too long). --}}
@extends('layouts.guest')

@section('title', 'Page expired')

@section('content')
<div class="auth-wrapper">
    <div class="card auth-card text-center">
        <div class="card-body p-5">
            <div class="display-6 text-warning mb-2"><i class="bi bi-hourglass-bottom"></i></div>
            <h1 class="h4 fw-bold">Page expired</h1>
            <p class="text-muted">Your session expired or the form was open for too long. Please go back, refresh the page and try again.</p>
            <a href="{{ url('/dashboard') }}" class="btn btn-primary">Back to dashboard</a>
        </div>
    </div>
</div>
@endsection
