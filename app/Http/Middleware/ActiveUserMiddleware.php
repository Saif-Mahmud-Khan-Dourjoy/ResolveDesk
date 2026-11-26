<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out a user whose account was deactivated while they were logged in.
 *
 * The login form already blocks inactive users, but someone who is already
 * logged in keeps a valid session. This middleware runs on every protected
 * request and re-reads is_active from the database (via $request->user()).
 */
class ActiveUserMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Your account has been deactivated. Please contact an administrator.';

            // AJAX/fetch requests (e.g. notification polling) get JSON instead of a redirect.
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 401);
            }

            return redirect()->route('login')->with('error', $message);
        }

        return $next($request);
    }
}
