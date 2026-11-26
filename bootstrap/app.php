<?php

use App\Http\Middleware\ActiveUserMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Short names so routes can write ->middleware('role:admin') and ->middleware('active').
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'active' => ActiveUserMiddleware::class,
        ]);

        // Where the "auth" middleware sends guests, and where "guest" sends logged-in users.
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
