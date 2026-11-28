<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Services\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * V2: validation for "Create Ticket" (a Form Request because the rules are long
 * and include attachments).
 *
 * This class checks FORMAT only (required, exists, file type...). The business
 * rules about scope - "may THIS user create a ticket for THIS project/client?" -
 * are checked in TicketService::resolveScope() inside the database transaction,
 * because they must be evaluated together with a lock on the project row.
 *
 * Laravel runs authorize() and rules() automatically before the controller method.
 * On failure it redirects back with errors (or returns 422 JSON).
 */
class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same as Gate::authorize('create', Ticket::class) -> TicketPolicy::create().
        return $this->user()->can('create', Ticket::class);
    }

    public function rules(): array
    {
        return array_merge([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // Checked against the database (not the cache): the category must exist AND be active.
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'priority' => ['required', Rule::in(array_keys(Ticket::PRIORITIES))],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            // Idempotency key generated when the form was rendered (hidden field).
            'request_token' => ['required', 'string', 'uuid'],
        ], AttachmentService::validationRules());
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'Please choose an active category.',
            'request_token.required' => 'The form expired. Please reload the page and try again.',
            'attachments.*.max' => 'Each attachment may be at most ' . (config('resolvedesk.attachments.max_file_kb') / 1024) . ' MB.',
            'attachments.*.mimes' => 'Allowed file types: ' . implode(', ', config('resolvedesk.attachments.allowed_extensions')) . '.',
            'attachments.*.extensions' => 'Allowed file types: ' . implode(', ', config('resolvedesk.attachments.allowed_extensions')) . '.',
        ];
    }
}
