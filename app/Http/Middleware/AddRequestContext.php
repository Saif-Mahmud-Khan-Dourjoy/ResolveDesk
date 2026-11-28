<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * V2: adds a request id (and the logged-in user id) to EVERY log line written
 * during this request, and returns the id in the X-Request-Id response header.
 *
 * Example log line:
 *   [2026-11-26 14:03:11] local.ERROR: Could not queue ticket email
 *   {"ticket_id":42,"error":"..."} {"request_id":"9b2f...","user_id":7}
 *
 * A user can report "X-Request-Id: 9b2f..." and an admin finds the exact log lines
 * with: grep 9b2f storage/logs/laravel.log
 * Only ids are logged here - never passwords, cookies or ticket text.
 */
class AddRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // Re-use an id set by a reverse proxy (Nginx) if it is a safe short value.
        $incomingId = (string) $request->headers->get('X-Request-Id', '');
        $requestId = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incomingId) ? $incomingId : (string) Str::uuid();

        Log::withContext([
            'request_id' => $requestId,
            'user_id' => $request->user()?->id,
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
