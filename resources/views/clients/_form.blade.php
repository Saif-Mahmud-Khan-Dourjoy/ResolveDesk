{{-- Shared fields for create/edit client. $client is null on the create page. --}}
@php($client = $client ?? null)

<div class="row">
    <div class="col-md-4 mb-3">
        <label for="code" class="form-label required">Code</label>
        <input type="text" id="code" name="code" maxlength="30" value="{{ old('code', $client?->code) }}"
               class="form-control text-uppercase @error('code') is-invalid @enderror" placeholder="e.g. ACME" required>
        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-8 mb-3">
        <label for="name" class="form-label required">Organization name</label>
        <input type="text" id="name" name="name" maxlength="150" value="{{ old('name', $client?->name) }}"
               class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Acme Trading Ltd." required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" id="email" name="email" value="{{ old('email', $client?->email) }}"
               class="form-control @error('email') is-invalid @enderror" placeholder="support@client.com">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label for="phone" class="form-label">Phone</label>
        <input type="text" id="phone" name="phone" maxlength="50" value="{{ old('phone', $client?->phone) }}"
               class="form-control @error('phone') is-invalid @enderror" placeholder="+880 1XXX-XXXXXX">
        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="mb-3">
    <label for="address" class="form-label">Address</label>
    <input type="text" id="address" name="address" maxlength="500" value="{{ old('address', $client?->address) }}"
           class="form-control @error('address') is-invalid @enderror" placeholder="Street, city">
    @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label for="description" class="form-label">Description</label>
    <textarea id="description" name="description" rows="3" class="form-control @error('description') is-invalid @enderror"
              placeholder="Notes about this organization">{{ old('description', $client?->description) }}</textarea>
    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="form-check form-switch mb-4">
    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
           @checked(old('is_active', $client?->is_active ?? true))>
    <label class="form-check-label" for="is_active">Active (its customers may create new tickets)</label>
</div>
