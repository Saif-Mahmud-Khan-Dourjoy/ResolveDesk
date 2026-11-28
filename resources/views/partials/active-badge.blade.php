{{-- Usage: @include('partials.active-badge', ['active' => $model->is_active]) --}}
@if ($active)
    <span class="badge bg-success-subtle text-success-emphasis">Active</span>
@else
    <span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>
@endif
