{{-- One statistic tile. Params: $label, $value, $icon, $color (Bootstrap color name), optional $link --}}
<div class="card stat-card">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <div class="stat-label">{{ $label }}</div>
            <div class="stat-value">{{ $value }}</div>
        </div>
        <span class="stat-icon bg-{{ $color }}-subtle text-{{ $color }}-emphasis"><i class="bi {{ $icon }}"></i></span>
    </div>
    @isset($link)
        <a href="{{ $link }}" class="small text-decoration-none stretched-link">View tickets</a>
    @endisset
</div>
