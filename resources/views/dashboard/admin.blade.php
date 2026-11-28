@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Operations Dashboard</h1>
        <p class="page-subtitle">Support workload and ticket statistics.</p>
    </div>
    <span class="small text-muted" title="Aggregate statistics are cached for 60 seconds and refreshed after every ticket change.">
        <i class="bi bi-database-check"></i> Statistics generated at {{ $stats['generated_at'] }}
    </span>
</div>

{{-- Statistic tiles --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl">
        @include('dashboard._stat-card', ['label' => 'Total tickets', 'value' => $stats['total'], 'icon' => 'bi-collection', 'color' => 'primary', 'link' => route('tickets.index')])
    </div>
    <div class="col-6 col-md-3 col-xl">
        @include('dashboard._stat-card', ['label' => 'Open', 'value' => $stats['status_counts']['open'], 'icon' => 'bi-envelope-open', 'color' => 'info', 'link' => route('tickets.index', ['status' => 'open'])])
    </div>
    <div class="col-6 col-md-3 col-xl">
        @include('dashboard._stat-card', ['label' => 'Assigned', 'value' => $stats['status_counts']['assigned'], 'icon' => 'bi-person-check', 'color' => 'primary', 'link' => route('tickets.index', ['status' => 'assigned'])])
    </div>
    <div class="col-6 col-md-3 col-xl">
        @include('dashboard._stat-card', ['label' => 'In progress', 'value' => $stats['status_counts']['in_progress'], 'icon' => 'bi-hourglass-split', 'color' => 'warning', 'link' => route('tickets.index', ['status' => 'in_progress'])])
    </div>
    <div class="col-6 col-md-3 col-xl">
        @include('dashboard._stat-card', ['label' => 'Resolved', 'value' => $stats['status_counts']['resolved'], 'icon' => 'bi-check2-circle', 'color' => 'success', 'link' => route('tickets.index', ['status' => 'resolved'])])
    </div>
    <div class="col-6 col-md-3 col-xl">
        @include('dashboard._stat-card', ['label' => 'Closed', 'value' => $stats['status_counts']['closed'], 'icon' => 'bi-lock', 'color' => 'secondary', 'link' => route('tickets.index', ['status' => 'closed'])])
    </div>
    <div class="col-6 col-md-3 col-xl">
        @include('dashboard._stat-card', ['label' => 'Overdue', 'value' => $overdueCount, 'icon' => 'bi-alarm', 'color' => 'danger', 'link' => route('tickets.index', ['overdue' => 1])])
    </div>
    @php($averageResolution = $stats['average_resolution_hours'] === null ? '—' : $stats['average_resolution_hours'] . ' h')
    <div class="col-6 col-md-3 col-xl">
        @include('dashboard._stat-card', ['label' => 'Avg. resolution', 'value' => $averageResolution, 'icon' => 'bi-speedometer2', 'color' => 'success'])
    </div>
</div>

{{-- V2: multi-project overview. "?? 0" keeps the page working if an older cache entry is still stored. --}}
@php($slaCompliance = $stats['sla_compliance'] ?? null)
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        @include('dashboard._stat-card', ['label' => 'Active projects', 'value' => $stats['active_projects'] ?? 0, 'icon' => 'bi-kanban', 'color' => 'primary', 'link' => route('projects.index', ['status' => 'active'])])
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        @include('dashboard._stat-card', ['label' => 'Active clients', 'value' => $stats['active_clients'] ?? 0, 'icon' => 'bi-building', 'color' => 'info', 'link' => route('clients.index', ['status' => 'active'])])
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        @include('dashboard._stat-card', ['label' => 'Active agents', 'value' => $stats['active_agents'] ?? 0, 'icon' => 'bi-headset', 'color' => 'secondary', 'link' => route('users.index', ['role' => 'agent', 'status' => 'active'])])
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        @include('dashboard._stat-card', ['label' => 'Global tickets', 'value' => $stats['global_count'] ?? 0, 'icon' => 'bi-globe', 'color' => 'secondary', 'link' => route('tickets.index', ['project_id' => 'global'])])
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        @include('dashboard._stat-card', ['label' => 'Project tickets', 'value' => $stats['project_ticket_count'] ?? 0, 'icon' => 'bi-diagram-3', 'color' => 'primary'])
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        @include('dashboard._stat-card', ['label' => 'SLA compliance', 'value' => $slaCompliance === null ? '—' : $slaCompliance . '%', 'icon' => 'bi-shield-check', 'color' => 'success', 'link' => route('reports.index')])
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Tickets by project</div>
            <div class="card-body"><canvas id="projectChart" height="160"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Tickets by client</div>
            <div class="card-body"><canvas id="clientChart" height="160"></canvas></div>
        </div>
    </div>
</div>

{{-- Charts --}}
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">Ticket creation trend (last 14 days)</div>
            <div class="card-body"><canvas id="trendChart" height="110"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Tickets by priority</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="priorityChart" style="max-height: 240px;"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">Tickets by category</div>
            <div class="card-body"><canvas id="categoryChart" height="190"></canvas></div>
        </div>

        <div class="card">
            <div class="card-header">Support agent workload</div>
            <div class="table-responsive">
                <table class="table align-middle table-card">
                    <thead>
                        <tr><th>Agent</th><th class="text-center">Active</th><th class="text-center">Overdue</th><th class="text-center">Resolved</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($agentWorkload as $agent)
                            <tr>
                                <td>
                                    <a href="{{ route('users.show', $agent) }}" class="text-decoration-none">{{ $agent->name }}</a>
                                    @unless ($agent->is_active)
                                        <span class="badge text-bg-light border">Inactive</span>
                                    @endunless
                                </td>
                                <td class="text-center fw-semibold">{{ $agent->active_count }}</td>
                                <td class="text-center {{ $agent->overdue_count > 0 ? 'text-danger fw-semibold' : '' }}">{{ $agent->overdue_count }}</td>
                                <td class="text-center">{{ $agent->resolved_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted text-center py-3">No support agents yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                Recent tickets
                <a href="{{ route('tickets.index') }}" class="small text-decoration-none fw-normal">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-card">
                    <thead>
                        <tr><th>Ticket</th><th>Subject</th><th>Status</th><th>Agent</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($recentTickets as $ticket)
                            <tr class="{{ $ticket->isOverdue() ? 'row-overdue' : '' }}">
                                <td class="text-nowrap"><a href="{{ route('tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_number }}</a></td>
                                <td>
                                    <div class="ticket-subject text-truncate">{{ $ticket->subject }}</div>
                                    <div class="small text-muted">{{ $ticket->category->name }} · {{ $ticket->creator->name }}</div>
                                </td>
                                <td class="text-nowrap">@include('partials.ticket-badges', ['ticket' => $ticket])</td>
                                <td class="text-nowrap small">{{ $ticket->assignedAgent?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted text-center py-3">No tickets yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @include('dashboard._recent-activity', ['activities' => $recentActivity])
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
<script>
    // Blade's @@json directive safely converts the PHP arrays from DashboardController into JavaScript objects.
    // (Inside Blade, "@@" prints a literal "@" so this comment is not compiled as a directive.)
    const trendData = @json($stats['trend']);
    const priorityData = @json($stats['priority_counts']);
    const categoryData = @json($stats['category_counts']);
    const projectData = @json($stats['project_counts'] ?? []);
    const clientData = @json($stats['client_counts'] ?? []);

    const accentColor = '#4f46e5';

    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: Object.keys(trendData),
            datasets: [{
                label: 'Tickets created',
                data: Object.values(trendData),
                borderColor: accentColor,
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                fill: true,
                tension: 0.3,
            }],
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });

    new Chart(document.getElementById('priorityChart'), {
        type: 'doughnut',
        data: {
            labels: Object.keys(priorityData),
            datasets: [{
                data: Object.values(priorityData),
                backgroundColor: ['#9ca3af', '#3b82f6', '#f97316', '#dc2626'], // low, medium, high, critical
            }],
        },
        options: { plugins: { legend: { position: 'bottom' } } },
    });

    new Chart(document.getElementById('categoryChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(categoryData),
            datasets: [{ label: 'Tickets', data: Object.values(categoryData), backgroundColor: accentColor, borderRadius: 4 }],
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    });

    // V2: one small helper for the two simple bar charts (project and client).
    function simpleBarChart(canvasId, data, color) {
        new Chart(document.getElementById(canvasId), {
            type: 'bar',
            data: {
                labels: Object.keys(data),
                datasets: [{ label: 'Tickets', data: Object.values(data), backgroundColor: color, borderRadius: 4 }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }
    simpleBarChart('projectChart', projectData, accentColor);
    simpleBarChart('clientChart', clientData, '#0ea5e9');
</script>
@endpush
