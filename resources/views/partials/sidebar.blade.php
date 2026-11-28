{{--
    Left sidebar. On large screens it is always visible; on small screens it
    becomes a Bootstrap "offcanvas" panel opened by the menu button in the navbar.
    request()->routeIs('tickets.*') highlights the current section.
--}}
<aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
    <div class="d-flex align-items-center justify-content-between px-3" style="height: 60px;">
        <a href="{{ route('dashboard') }}" class="brand" id="appSidebarLabel">
            <span class="brand-logo"><i class="bi bi-life-preserver"></i></span>
            ResolveDesk
        </a>
        <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close"></button>
    </div>

    <nav class="pb-4 w-100">
        <div class="sidebar-heading">Main</div>

        <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="bi bi-grid-1x2"></i> Dashboard
        </a>

        <a href="{{ route('tickets.index') }}" class="sidebar-link {{ request()->routeIs('tickets.index', 'tickets.show', 'tickets.edit') ? 'active' : '' }}">
            <i class="bi bi-ticket-detailed"></i>
            {{ auth()->user()->isAdmin() ? 'All Tickets' : 'My Tickets' }}
        </a>

        <a href="{{ route('tickets.create') }}" class="sidebar-link {{ request()->routeIs('tickets.create') ? 'active' : '' }}">
            <i class="bi bi-plus-circle"></i> New Ticket
        </a>

        <a href="{{ route('notifications.index') }}" class="sidebar-link {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
            <i class="bi bi-bell"></i> Notifications
        </a>

        {{-- Admin-only links. The routes are ALSO protected by the role:admin middleware. --}}
        @if (auth()->user()->isAdmin())
            <div class="sidebar-heading">Administration</div>

            <a href="{{ route('projects.index') }}" class="sidebar-link {{ request()->routeIs('projects.*') ? 'active' : '' }}">
                <i class="bi bi-kanban"></i> Projects
            </a>

            <a href="{{ route('clients.index') }}" class="sidebar-link {{ request()->routeIs('clients.*') ? 'active' : '' }}">
                <i class="bi bi-building"></i> Clients
            </a>

            <a href="{{ route('reports.index') }}" class="sidebar-link {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                <i class="bi bi-bar-chart-line"></i> Reports
            </a>

            <a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                <i class="bi bi-people"></i> Users
            </a>

            <a href="{{ route('categories.index') }}" class="sidebar-link {{ request()->routeIs('categories.*') ? 'active' : '' }}">
                <i class="bi bi-tags"></i> Categories
            </a>
        @endif
    </nav>
</aside>
