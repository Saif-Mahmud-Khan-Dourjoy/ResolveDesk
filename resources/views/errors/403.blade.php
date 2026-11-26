{{-- Laravel automatically shows this view for HTTP 403 (Forbidden) errors, e.g. a failed policy check. --}}
@extends('layouts.guest')

@section('title', 'Access denied')

@section('content')
<div class="auth-wrapper">
    <div class="card auth-card text-center">
        <div class="card-body p-5">
            <div class="display-6 text-danger mb-2"><i class="bi bi-shield-lock"></i></div>
            <h1 class="h4 fw-bold">Access denied</h1>
            <p class="text-muted">
                {{ $exception->getMessage() ?: 'You do not have permission to perform this action.' }}
            </p>
            <p class="text-muted small">If you opened this from a notification, the ticket may have been reassigned or your access may have changed.</p>
            <a href="{{ url('/dashboard') }}" class="btn btn-primary">Back to dashboard</a>
        </div>
    </div>
</div>
@endsection
