<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketCommentController extends Controller
{
    public function __construct(private TicketService $ticketService)
    {
    }

    public function store(Request $request, Ticket $ticket)
    {
        // Must be allowed to view the ticket, and the ticket must not be Closed.
        Gate::authorize('comment', $ticket);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $this->ticketService->addComment($ticket, $validated['message'], $request->user());

        return redirect()->to(route('tickets.show', $ticket) . '#conversation')
            ->with('success', 'Comment added.');
    }
}
