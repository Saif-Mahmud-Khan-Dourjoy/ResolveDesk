<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks (V2)
|--------------------------------------------------------------------------
| The server runs ONE cron entry every minute:
|     * * * * * cd /var/www/resolvedesk && php artisan schedule:run >> /dev/null 2>&1
| Locally: php artisan schedule:work   (runs the scheduler in the foreground)
| List:    php artisan schedule:list
|
| withoutOverlapping(): if a run is still busy after a minute, the next run is
| skipped instead of starting a second copy (uses a cache lock).
| onOneServer() is NOT used: this project is a single-server deployment. With
| several servers it would need a shared cache (database/Redis) - see the docs.
*/

Schedule::command('tickets:check-sla')
    ->everyMinute()
    ->withoutOverlapping();
