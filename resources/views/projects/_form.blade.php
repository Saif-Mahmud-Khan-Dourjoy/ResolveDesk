{{-- Shared fields for create/edit project. $project is null on the create page. --}}
@php($project = $project ?? null)

<div class="row">
    <div class="col-md-4 mb-3">
        <label for="code" class="form-label required">Code</label>
        <input type="text" id="code" name="code" maxlength="30" value="{{ old('code', $project?->code) }}"
               class="form-control text-uppercase @error('code') is-invalid @enderror" placeholder="e.g. HRMS" required>
        <div class="form-text">Unique short code (letters, numbers, - and _).</div>
        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-8 mb-3">
        <label for="name" class="form-label required">Name</label>
        <input type="text" id="name" name="name" maxlength="150" value="{{ old('name', $project?->name) }}"
               class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Human Resource Management System" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="mb-3">
    <label for="description" class="form-label">Description</label>
    <textarea id="description" name="description" rows="3" class="form-control @error('description') is-invalid @enderror"
              placeholder="What does this product do?">{{ old('description', $project?->description) }}</textarea>
    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="form-check form-switch mb-4">
    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
           @checked(old('is_active', $project?->is_active ?? true))>
    <label class="form-check-label" for="is_active">Active (selectable for new tickets)</label>
</div>
