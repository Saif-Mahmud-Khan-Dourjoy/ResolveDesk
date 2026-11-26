<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
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
    }
}
