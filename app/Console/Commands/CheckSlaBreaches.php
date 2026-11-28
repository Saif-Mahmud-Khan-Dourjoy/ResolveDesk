<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Throwable;

/**
 * V2: sends ONE reminder per SLA breach.
 *
 *   php artisan tickets:check-sla
 *
 * Scheduled every minute in routes/console.php (withoutOverlapping), so on a
 * server you only need the standard cron entry that runs `php artisan schedule:run`.
 *
 * Steps:
 *   1. Find overdue active tickets that were not notified yet:
 *        WHERE status IN ('open','assigned','in_progress') AND due_at < NOW()
 *          AND sla_notified_at IS NULL
 *   2. Walk through them 100 at a time with chunkById() (never loads the whole table).
 *   3. TicketService::processSlaBreach() locks each row, re-checks it and marks it,
 *      so a ticket is notified only once even if two runs overlap.
 */
class CheckSlaBreaches extends Command
{
    protected $signature = 'tickets:check-sla';

    protected $description = 'Notify agents/admins about tickets that passed their SLA deadline (once per breach)';

    public function handle(TicketService $ticketService): int
    {
        $notifiedCount = 0;
        $failedCount = 0;

        Ticket::overdue()
            ->whereNull('sla_notified_at')
            ->select('id')
            ->chunkById(100, function ($tickets) use ($ticketService, &$notifiedCount, &$failedCount) {
                foreach ($tickets as $ticket) {
                    try {
                        if ($ticketService->processSlaBreach($ticket)) {
                            $notifiedCount++;
                        }
                    } catch (Throwable $exception) {
                        // One broken ticket must not stop the others.
                        $failedCount++;
                        report($exception);
                    }
                }
            });

        $this->info("SLA check finished: {$notifiedCount} breach notification(s) sent, {$failedCount} failure(s).");

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
