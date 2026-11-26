@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="text-center mb-4">
            <span class="brand justify-content-center">
                <span class="brand-logo"><i class="bi bi-life-preserver"></i></span>
                ResolveDesk
            </span>
            <p class="text-muted mt-2 mb-0">Customer Support &amp; Workflow Automation</p>
        </div>

        <div class="card">
            <div class="card-body p-4">
                <h1 class="h5 fw-bold mb-1">Sign in to your account</h1>
                <p class="text-muted small mb-4">Accounts are created by an administrator.</p>

                @if (session('success'))
                    <div class="alert alert-success py-2 small">{{ session('success') }}</div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger py-2 small">{{ session('error') }}</div>
                @endif

                <form method="POST" action="{{ route('login.attempt') }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="email" class="form-label required">Email address</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}"
                               class="form-control @error('email') is-invalid @enderror"
                               placeholder="you@company.com" required autofocus autocomplete="username">
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label required">Password</label>
                        <input type="password" id="password" name="password"
                               class="form-control @error('password') is-invalid @enderror"
                               placeholder="Your password" required autocomplete="current-password">
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign in
                    </button>
                </form>
            </div>
        </div>

        @if (app()->environment('local'))
            <div class="card mt-3">
                <div class="card-body small">
                    <div class="fw-semibold mb-1"><i class="bi bi-info-circle"></i> Local demo accounts (password: <code>password</code>)</div>
                    <div>Admin: <code>admin@resolvedesk.test</code></div>
                    <div>Agent: <code>sarah.agent@resolvedesk.test</code></div>
                    <div>Customer: <code>john.customer@example.com</code></div>
                    <div class="text-muted mt-1">Shown only when APP_ENV=local. For local demonstration only.</div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
