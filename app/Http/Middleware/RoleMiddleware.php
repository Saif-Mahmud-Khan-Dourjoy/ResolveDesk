<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * General role check used in routes/web.php.
 *
 * Usage:  ->middleware('role:admin')          only administrators
 *         ->middleware('role:admin,agent')    administrators or agents
 *
 * Laravel passes everything after the colon as extra arguments, so
 * "role:admin,agent" arrives here as $roles = ['admin', 'agent'].
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! in_array($user->role, $roles, true)) {
            abort(403, 'You do not have permission to access this page.');
        }

        return $next($request);
    }
}
