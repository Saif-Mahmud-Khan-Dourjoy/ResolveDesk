<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 Phase 7: database-level concurrency checks with TWO real MySQL connections.
 *
 * Normal feature tests (RefreshDatabase) run inside one transaction on one
 * connection, so they cannot show what a second, simultaneous request sees.
 * Here the second connection ("mysql_second") is a separate session, exactly
 * like a second PHP-FPM worker. The data must really be COMMITTED for the other
 * connection to see it, so the tables are truncated in tearDown().
 *
 * These checks prove the InnoDB behaviour our code relies on (row locks, unique
 * index). They need MySQL/MariaDB - SQLite would not prove anything here.
 */
class MySqlConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql_second' => config('database.connections.mysql')]);
        // Do not wait the default 50 seconds for a lock in these tests.
        DB::connection('mysql_second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql_second');

        // Leave the testing database empty again: the other test classes use
        // RefreshDatabase and expect no committed rows from this class.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_row_lock_blocks_a_second_connection_until_commit(): void
    {
        $ticket = Ticket::factory()->create();

        DB::beginTransaction();
        Ticket::whereKey($ticket->id)->lockForUpdate()->first(); // request A holds the row lock

        try {
            // Request B tries to lock the same ticket (as TicketService::lockTicket does).
            DB::connection('mysql_second')->table('tickets')->where('id', $ticket->id)->lockForUpdate()->first();
            $this->fail('The second connection should have waited for the row lock.');
        } catch (QueryException $exception) {
            // 1205 = Lock wait timeout exceeded: B really had to wait for A.
            $this->assertSame(1205, $exception->errorInfo[1]);
        } finally {
            DB::rollBack();
        }

        // After A finished, B gets the lock immediately.
        $row = DB::connection('mysql_second')->table('tickets')->where('id', $ticket->id)->lockForUpdate()->first();
        $this->assertNotNull($row);
    }

    public function test_unique_request_token_rejects_a_parallel_duplicate_insert(): void
    {
        $creator = User::factory()->customer()->create();
        $category = Category::factory()->create();
        $row = [
            'subject' => 'Double submit', 'description' => 'x', 'category_id' => $category->id,
            'created_by' => $creator->id, 'priority' => 'low', 'status' => 'open', 'version' => 1,
            'due_at' => now()->addDay(), 'request_token' => 'same-token-123', 'created_at' => now(), 'updated_at' => now(),
        ];

        // Request A inserts and commits.
        DB::table('tickets')->insert($row);

        // Request B (other connection) inserts the same creator + token.
        try {
            DB::connection('mysql_second')->table('tickets')->insert($row);
            $this->fail('The UNIQUE(created_by, request_token) index must reject the duplicate.');
        } catch (QueryException $exception) {
            $this->assertSame(1062, $exception->errorInfo[1]); // 1062 = Duplicate entry
        }

        $this->assertSame(1, DB::table('tickets')->where('request_token', 'same-token-123')->count());
    }

    /**
     * Audit finding A-2 (race 1): an assignment transaction is still running (not yet
     * committed) when an administrator deactivates the same agent.
     *
     * A separate PHP process (a second "request") assigns the ticket and holds its
     * transaction open for 2 seconds. Meanwhile the real HTTP deactivation runs here.
     * Without a row lock the deactivation read "0 active tickets" from its snapshot,
     * waited, and then deactivated the agent anyway -> inactive agent owns a ticket.
     * Now it locks the agent row first and counts tickets only after the other commit.
     */
    public function test_deactivation_waits_for_a_running_assignment_and_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $otherRequest = $this->startBackgroundTransaction([
            "UPDATE tickets SET assigned_to = {$agent->id}, status = 'assigned', version = version + 1 WHERE id = {$ticket->id}",
        ], 2);

        try {
            $this->actingAs($admin)->patch(route('users.toggle-active', $agent))->assertSessionHasErrors('is_active');
        } finally {
            $this->finishBackgroundTransaction($otherRequest);
        }

        $this->assertTrue($agent->fresh()->is_active, 'The agent must stay active: they own an assigned ticket.');
        $this->assertSame($agent->id, $ticket->fresh()->assigned_to);
    }

    /**
     * Audit finding A-2 (race 2): the reverse order. A deactivation is running (not yet
     * committed) when an administrator assigns a ticket to that agent. The assignment
     * must re-read the agent WITH a lock, so it sees is_active = 0 and refuses.
     */
    public function test_assignment_waits_for_a_running_deactivation_and_is_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $otherRequest = $this->startBackgroundTransaction([
            "SELECT id FROM users WHERE id = {$agent->id} FOR UPDATE",
            "UPDATE users SET is_active = 0 WHERE id = {$agent->id}",
        ], 2);

        try {
            $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id])
                ->assertSessionHasErrors('agent_id');
        } finally {
            $this->finishBackgroundTransaction($otherRequest);
        }

        $this->assertFalse($agent->fresh()->is_active);
        $this->assertNull($ticket->fresh()->assigned_to, 'An inactive agent must never receive a ticket.');
    }

    /**
     * Starts a separate PHP process that runs $statements inside one transaction,
     * prints LOCKED, keeps the transaction (and its row locks) open for $holdSeconds
     * and then commits. Returns once the locks are really held.
     */
    private function startBackgroundTransaction(array $statements, int $holdSeconds): array
    {
        $config = config('database.connections.mysql');
        $script = sprintf(
            '$pdo = new PDO(%s, %s, %s, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            . '$pdo->beginTransaction(); foreach (%s as $sql) { $pdo->query($sql)->fetchAll(); }'
            . 'echo "LOCKED\n"; flush(); sleep(%d); $pdo->commit(); echo "COMMITTED\n";',
            var_export("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", true),
            var_export($config['username'], true),
            var_export((string) $config['password'], true),
            var_export($statements, true),
            $holdSeconds
        );

        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $firstLine = trim((string) fgets($pipes[1]));
        if ($firstLine !== 'LOCKED') {
            // Read stderr ONLY on failure: reading it blocks until the process exits.
            $this->fail('Background transaction failed: ' . stream_get_contents($pipes[2]));
        }

        return [$process, $pipes];
    }

    private function finishBackgroundTransaction(array $handle): void
    {
        [$process, $pipes] = $handle;
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $this->assertStringContainsString('COMMITTED', $output);
    }

    public function test_optimistic_update_with_a_stale_version_changes_nothing(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Original', 'version' => 1]);

        // Two users loaded version 1. User A saves first.
        $updatedByA = DB::table('tickets')->where('id', $ticket->id)->where('version', 1)
            ->update(['subject' => 'A', 'version' => DB::raw('version + 1')]);

        // User B saves with the old version on another connection.
        $updatedByB = DB::connection('mysql_second')->table('tickets')->where('id', $ticket->id)->where('version', 1)
            ->update(['subject' => 'B', 'version' => DB::raw('version + 1')]);

        $this->assertSame(1, $updatedByA);
        $this->assertSame(0, $updatedByB, '0 rows = conflict detected, B must reload');
        $this->assertSame('A', $ticket->fresh()->subject);
        $this->assertSame(2, $ticket->fresh()->version);
    }
}
