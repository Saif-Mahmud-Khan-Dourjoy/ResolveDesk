{{-- Main layout for every logged-in page. Child views use @extends('layouts.app'). --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- JavaScript reads this token and sends it with POST requests (CSRF protection). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- V2: realtime.js subscribes to the private channel users.{this id}. The server
         still checks that the logged-in user owns the channel (routes/channels.php). --}}
    <meta name="user-id" content="{{ auth()->id() }}">
    <title>@yield('title', 'Dashboard') · ResolveDesk</title>

    {{-- Local copies of Bootstrap, Bootstrap Icons and our own CSS (works offline). --}}
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="app-shell">
    @include('partials.sidebar')

    <div class="app-main">
        @include('partials.navbar')

        <main class="app-content">
            @hasSection('breadcrumbs')
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
                        @yield('breadcrumbs')
                    </ol>
                </nav>
            @endif

            @include('partials.flash')

            @yield('content')
        </main>
    </div>
</div>

{{-- Bootstrap toasts for new notifications are added here by notifications.js --}}
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/app.js') }}"></script>
<script src="{{ asset('js/notifications.js') }}"></script>
{{-- V2 real-time bundle (Echo + Reverb), built with `npm run build`. If it was never built,
     the page still works: notifications.js simply keeps polling. --}}
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
    @vite('resources/js/realtime.js')
@endif
@stack('scripts')
</body>
</html>
