{{-- Shared fields for create/edit user. $user is null on the create page. --}}
@php($user = $user ?? null)
@php($isEdit = $user !== null)

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="name" class="form-label required">Full name</label>
        <input type="text" id="name" name="name" value="{{ old('name', $user?->name) }}"
               class="form-control @error('name') is-invalid @enderror" placeholder="e.g. Sarah Ahmed" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label for="email" class="form-label required">Email address</label>
        <input type="email" id="email" name="email" value="{{ old('email', $user?->email) }}"
               class="form-control @error('email') is-invalid @enderror" placeholder="name@company.com" required>
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="password" class="form-label {{ $isEdit ? '' : 'required' }}">Password</label>
        <input type="password" id="password" name="password" autocomplete="new-password"
               class="form-control @error('password') is-invalid @enderror"
               placeholder="{{ $isEdit ? 'Leave blank to keep the current password' : 'At least 8 characters' }}" {{ $isEdit ? '' : 'required' }}>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3">
        <label for="password_confirmation" class="form-label {{ $isEdit ? '' : 'required' }}">Confirm password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password"
               class="form-control" placeholder="Repeat the password" {{ $isEdit ? '' : 'required' }}>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="role" class="form-label required">Role</label>
        <select id="role" name="role" class="form-select @error('role') is-invalid @enderror" required>
            @foreach (\App\Models\User::ROLES as $value => $label)
                <option value="{{ $value }}" @selected(old('role', $user?->role ?? 'customer') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6 mb-3 d-flex align-items-end">
        <div class="form-check form-switch mb-2">
            {{-- Unchecked checkboxes are not submitted; the controller uses $request->boolean('is_active'). --}}
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                   @checked(old('is_active', $user?->is_active ?? true))>
            <label class="form-check-label" for="is_active">Active (can sign in)</label>
        </div>
    </div>
</div>
@error('is_active')<div class="alert alert-danger small">{{ $message }}</div>@enderror
