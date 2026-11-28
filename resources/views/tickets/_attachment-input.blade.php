{{-- File input shared by the create form and the comment form. Limits come from config/resolvedesk.php. --}}
@php($attachmentConfig = config('resolvedesk.attachments'))
<label for="attachments" class="form-label small mb-1">Attachments <span class="text-muted">(optional)</span></label>
<input type="file" id="attachments" name="attachments[]" multiple
       accept="{{ '.' . implode(',.', $attachmentConfig['allowed_extensions']) }}"
       class="form-control form-control-sm @error('attachments') is-invalid @enderror @error('attachments.*') is-invalid @enderror">
<div class="form-text">
    Up to {{ $attachmentConfig['max_files'] }} files, {{ $attachmentConfig['max_file_kb'] / 1024 }} MB each:
    {{ implode(', ', $attachmentConfig['allowed_extensions']) }}.
</div>
@error('attachments')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
@foreach ($errors->get('attachments.*') as $fileErrors)
    @foreach ($fileErrors as $fileError)
        <div class="invalid-feedback d-block">{{ $fileError }}</div>
    @endforeach
@endforeach
