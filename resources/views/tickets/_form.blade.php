{{--
    Shared fields for the create and edit ticket forms.
    $ticket is null on the create page. old() restores values after a validation error.
--}}
@php($ticket = $ticket ?? null)

<div class="mb-3">
    <label for="subject" class="form-label required">Subject</label>
    <input type="text" id="subject" name="subject" maxlength="255"
           value="{{ old('subject', $ticket?->subject) }}"
           class="form-control @error('subject') is-invalid @enderror"
           placeholder="Short summary, e.g. Cannot export invoices to PDF" required>
    @error('subject')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="description" class="form-label required">Description</label>
    <textarea id="description" name="description" rows="6"
              class="form-control @error('description') is-invalid @enderror"
              placeholder="Describe the problem, the steps to reproduce it and what you expected to happen." required>{{ old('description', $ticket?->description) }}</textarea>
    @error('description')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="category_id" class="form-label required">Category</label>
        <select id="category_id" name="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
            <option value="">Select a category</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('category_id', $ticket?->category_id) === (string) $category->id)>
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
        @error('category_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6 mb-3">
        <label for="priority" class="form-label required">Priority</label>
        <select id="priority" name="priority" class="form-select @error('priority') is-invalid @enderror" required>
            @foreach (\App\Models\Ticket::PRIORITIES as $value => $label)
                <option value="{{ $value }}" @selected(old('priority', $ticket?->priority ?? 'medium') === $value)>
                    {{ $label }} — resolve within {{ \App\Models\Ticket::SLA_HOURS[$value] }} hours
                </option>
            @endforeach
        </select>
        @error('priority')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">The SLA deadline is calculated from the ticket's creation time.</div>
    </div>
</div>
