<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\TicketAssignmentService;
use App\Services\TicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression tests for defects found in the production-readiness audit
 * (docs/PRODUCTION-READINESS-REPORT.md). Each test failed before its fix.
 */
class AuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * Finding A-1: the controller authorizes the route-bound ticket, which was read
     * BEFORE the row lock. If the ticket is reassigned in between, the old agent's
     * request must still be refused - the service re-checks the policy on the locked row.
     * (A request without "version" skips the optimistic check, so only this re-check helps.)
     */
    public function test_previous_agent_cannot_resolve_using_a_stale_ticket_copy(): void
    {
        $oldAgent = User::factory()->agent()->create();
        $newAgent = User::factory()->agent()->create();
        $staleTicket = Ticket::factory()->inProgress($oldAgent)->create();

        // Another admin reassigns the ticket after the old agent's request loaded it.
        app(TicketAssignmentService::class)->assignAgent(Ticket::find($staleTicket->id), $newAgent, $this->admin);

        $this->expectException(AuthorizationException::class);

        try {
            app(TicketService::class)->resolve($staleTicket, 'Fixed', $oldAgent);
        } finally {
            $this->assertSame(Ticket::STATUS_IN_PROGRESS, $staleTicket->fresh()->status);
        }
    }

    public function test_previous_agent_cannot_comment_using_a_stale_ticket_copy(): void
    {
        $oldAgent = User::factory()->agent()->create();
        $newAgent = User::factory()->agent()->create();
        $staleTicket = Ticket::factory()->inProgress($oldAgent)->create();

        app(TicketAssignmentService::class)->assignAgent(Ticket::find($staleTicket->id), $newAgent, $this->admin);

        try {
            app(TicketService::class)->addComment($staleTicket, 'internal detail', TicketComment::VISIBILITY_INTERNAL, $oldAgent);
            $this->fail('The previous agent must not be able to comment any more.');
        } catch (AuthorizationException) {
            $this->assertSame(0, TicketComment::count());
        }
    }

    /**
     * Finding A-3: the SLA reminder only sets internal bookkeeping (sla_notified_at).
     * It must not bump "version", otherwise every open ticket page/edit form of an
     * overdue ticket gets a false "changed by someone else" conflict.
     */
    public function test_sla_reminder_does_not_invalidate_open_forms(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create(['due_at' => now()->subHour(), 'version' => 2]);

        Artisan::call('tickets:check-sla');

        $this->assertNotNull($ticket->fresh()->sla_notified_at);
        $this->assertSame(2, $ticket->fresh()->version);

        // The agent's page was rendered with version 2: "Start Working" still works.
        $this->actingAs($agent)->post(route('tickets.start', $ticket), ['version' => 2])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $ticket->fresh()->status);
    }

    /**
     * Finding A-4: after a 409 conflict the edit form is shown again with the user's
     * input. The hidden version must be the CURRENT one; re-sending the stale
     * old('version') made every further save fail with the same conflict.
     */
    public function test_edit_form_after_a_conflict_carries_the_current_version(): void
    {
        $ticket = Ticket::factory()->create(['version' => 5]);

        $response = $this->actingAs($this->admin)
            ->withSession(['_old_input' => ['version' => 4, 'subject' => 'My edit']])
            ->get(route('tickets.edit', $ticket));

        $response->assertOk();
        $response->assertSee('name="version" value="5"', false);
        $response->assertSee('My edit');
    }
}
