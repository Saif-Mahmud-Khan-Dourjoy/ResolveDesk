<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

/**
 * V2: thrown when a request is based on outdated data (optimistic locking) or
 * re-uses a ticket request token with different content (idempotency conflict).
 *
 * Response (Laravel calls render() automatically):
 *   - JSON / AJAX request: HTTP 409 Conflict + {"message": "..."}
 *   - Normal form post:    redirect back WITH the user's input and an error flash,
 *                          so nothing typed is lost and the user can review the
 *                          latest ticket data first. (Documented equivalent of 409.)
 */
class TicketConflictException extends Exception
{
    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->withInput()->with('error', $this->getMessage());
    }

    public static function staleVersion(): self
    {
        return new self('This ticket was changed by someone else after you opened the page. '
            . 'Please review the latest version and try again.');
    }
}
