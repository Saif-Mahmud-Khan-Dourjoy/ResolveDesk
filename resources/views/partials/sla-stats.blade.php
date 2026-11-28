{{--
    SLA statistic cards. $stats comes from Ticket::slaSummary($query).
    Usage: @include('partials.sla-stats', ['stats' => $stats])
--}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2">
        <div class="card stat-card"><div class="stat-label">Total tickets</div><div class="stat-value">{{ $stats['total'] }}</div></div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="card stat-card"><div class="stat-label">Active</div><div class="stat-value">{{ $stats['active'] }}</div></div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="card stat-card"><div class="stat-label">Overdue now</div><div class="stat-value {{ $stats['overdue'] > 0 ? 'text-danger' : '' }}">{{ $stats['overdue'] }}</div></div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="card stat-card"><div class="stat-label">Resolved within SLA</div><div class="stat-value text-success">{{ $stats['resolved_within_sla'] }}</div></div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="card stat-card"><div class="stat-label">Resolved after SLA</div><div class="stat-value">{{ $stats['resolved_after_sla'] }}</div></div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="card stat-card" title="Resolved within SLA ÷ all resolved/closed tickets">
            <div class="stat-label">SLA compliance</div>
            <div class="stat-value">{{ $stats['sla_compliance'] === null ? '—' : $stats['sla_compliance'] . '%' }}</div>
        </div>
    </div>
</div>
