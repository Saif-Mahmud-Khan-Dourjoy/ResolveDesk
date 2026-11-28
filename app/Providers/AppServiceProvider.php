<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Pagination links use Bootstrap 5 HTML.
        Paginator::useBootstrapFive();

        // Outside production, throw an error when a relationship is lazy-loaded
        // inside a loop (the N+1 query problem). This forces us to use with().
        Model::preventLazyLoading(! $this->app->isProduction());

        $this->configureRateLimiting();
    }

    /**
     * V2: named rate limiters, used in routes/web.php as ->middleware('throttle:NAME').
     *
     * Logged-in users are limited per USER id (so colleagues behind one office IP
     * do not block each other); guests per IP address. When a limit is exceeded
     * Laravel returns HTTP 429 (resources/views/errors/429.blade.php, or JSON).
     * Login has its own limiter in AuthController (5 attempts per email + IP).
     */
    private function configureRateLimiting(): void
    {
        $byUserOrIp = fn (Request $request) => $request->user()?->id ?: $request->ip();

        // Creating tickets: a person rarely needs more than 10 per minute.
        RateLimiter::for('ticket-create', fn (Request $request) => Limit::perMinute(10)->by('ticket-create:' . $byUserOrIp($request)));

        // Comments and internal notes (including attachment uploads).
        RateLimiter::for('comments', fn (Request $request) => Limit::perMinute(30)->by('comments:' . $byUserOrIp($request)));

        // Notification feed: polling every 5 s = 12/min per tab; allow several tabs + real-time fetches.
        RateLimiter::for('notifications', fn (Request $request) => Limit::perMinute(120)->by('notifications:' . $byUserOrIp($request)));

        // CSV export runs a large query: 5 per minute per admin.
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(5)->by('exports:' . $byUserOrIp($request)));
    }
}
