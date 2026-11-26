{{-- Top navbar: mobile menu button, notification bell, user profile dropdown. --}}
<header class="app-navbar d-flex align-items-center justify-content-between px-3 px-md-4">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu">
            <i class="bi bi-list"></i>
        </button>
        <span class="fw-semibold text-secondary d-none d-sm-inline">@yield('title', 'Dashboard')</span>
    </div>

    <div class="d-flex align-items-center gap-2">
        {{--
            Notification bell. notifications.js fills the badge and the list.
            The data-* attributes give JavaScript the URLs so they are not hard-coded in JS.
        --}}
        <div class="dropdown" id="notificationBell"
             data-feed-url="{{ route('notifications.feed') }}"
             data-read-url="{{ route('notifications.read', ['id' => '__ID__']) }}"
             data-read-all-url="{{ route('notifications.read-all') }}"
             data-login-url="{{ route('login') }}">
            <button class="btn btn-light position-relative" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell fs-5"></i>
                <span class="badge rounded-pill bg-danger notification-badge d-none" id="notificationCount">0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notification-menu p-0 shadow-sm">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <strong>Notifications</strong>
                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="markAllReadButton">Mark all as read</button>
                </div>
                <div id="notificationList">
                    <div class="text-muted small p-3">Loading...</div>
                </div>
                <a href="{{ route('notifications.index') }}" class="d-block text-center small py-2 text-decoration-none">View all notifications</a>
            </div>
        </div>

        {{-- User profile dropdown --}}
        <div class="dropdown">
            <button class="btn btn-light d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar" style="width: 30px; height: 30px; font-size: 0.75rem;">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="d-none d-md-inline text-start lh-sm">
                    <span class="d-block fw-semibold small">{{ auth()->user()->name }}</span>
                    <span class="d-block text-muted" style="font-size: 0.72rem;">{{ auth()->user()->roleLabel() }}</span>
                </span>
                <i class="bi bi-chevron-down small text-muted"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li class="px-3 py-2 small text-muted">{{ auth()->user()->email }}</li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    {{-- Logout is a POST form (with CSRF token), never a GET link. --}}
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
