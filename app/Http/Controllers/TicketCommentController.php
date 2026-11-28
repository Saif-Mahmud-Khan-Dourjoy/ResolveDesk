<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\AttachmentService;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TicketCommentController extends Controller
{
    public function __construct(private TicketService $ticketService) {}

    /**
     * POST /tickets/{ticket}/comments
     * Fields: message, visibility (public|internal, staff only), attachments[] (optional).
     */
    public function store(Request $request, Ticket $ticket)
    {
        // Must be allowed to view the ticket, and the ticket must not be Closed.
        Gate::authorize('comment', $ticket);

        $validated = $request->validate(array_merge([
            'message' => ['required', 'string', 'max:5000'],
            'visibility' => ['nullable', Rule::in(array_keys(TicketComment::VISIBILITIES))],
        ], AttachmentService::validationRules()));

        $visibility = $validated['visibility'] ?? TicketComment::VISIBILITY_PUBLIC;

        // Internal notes need the extra staff-only permission (a customer gets 403,
        // even if they edit the HTML form to send visibility=internal).
        if ($visibility === TicketComment::VISIBILITY_INTERNAL) {
            Gate::authorize('addInternalNote', $ticket);
        }

        $this->ticketService->addComment(
            $ticket,
            $validated['message'],
            $visibility,
            $request->user(),
            $request->file('attachments', [])
        );

        $word = $visibility === TicketComment::VISIBILITY_INTERNAL ? 'Internal note' : 'Comment';

        return redirect()->to(route('tickets.show', $ticket) . '#conversation')
            ->with('success', "{$word} added.");
    }
}
