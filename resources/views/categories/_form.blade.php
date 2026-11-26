{{-- Shared fields for create/edit category. $category is null on the create page. --}}
@php($category = $category ?? null)

<div class="mb-3">
    <label for="name" class="form-label required">Name</label>
    <input type="text" id="name" name="name" maxlength="100" value="{{ old('name', $category?->name) }}"
           class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Billing" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="description" class="form-label">Description</label>
    <textarea id="description" name="description" rows="3" class="form-control @error('description') is-invalid @enderror"
              placeholder="What kind of issues belong in this category?">{{ old('description', $category?->description) }}</textarea>
    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="form-check form-switch mb-4">
    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
           @checked(old('is_active', $category?->is_active ?? true))>
    <label class="form-check-label" for="is_active">Active (shown in new ticket forms)</label>
</div>
