<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmail;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Assignment, auto-assignment, reassignment and the status lifecycle.
 */
class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // email jobs are recorded, not executed
        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->customer()->create();
    }

    // ---- Manual assignment ----------------------------------------------------

    public function test_admin_can_assign_an_open_ticket(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create(['created_by' => $this->customer->id]);

        $this->actingAs($this->admin)
            ->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id])
            ->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame($agent->id, $ticket->assigned_to);
        $this->assertSame(Ticket::STATUS_ASSIGNED, $ticket->status);
        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'action' => 'assigned', 'new_value' => $agent->name]);
        $this->assertSame(1, $agent->notifications()->count());
        $this->assertSame('assigned', $agent->notifications()->first()->data['event_type']);
    }

    public function test_cannot_assign_inactive_agent_or_customer(): void
    {
        $inactiveAgent = User::factory()->agent()->inactive()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('tickets.assign', $ticket), ['agent_id' => $inactiveAgent->id])
            ->assertSessionHasErrors('agent_id');

        $this->actingAs($this->admin)
            ->post(route('tickets.assign', $ticket), ['agent_id' => $this->customer->id])
            ->assertSessionHasErrors('agent_id');

        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status);
    }

    public function test_assigning_the_same_agent_again_creates_no_duplicates(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($this->admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id]);
        $this->actingAs($this->admin)
            ->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id])
            ->assertSessionHas('info');

        $this->assertSame(1, TicketActivity::where('action', 'assigned')->count());
        $this->assertSame(1, $agent->notifications()->count());
        Queue::assertPushed(SendTicketEmail::class, 1);
    }

    // ---- Automatic assignment -------------------------------------------------

    public function test_auto_assign_picks_agent_with_lowest_workload(): void
    {
        $busyAgent = User::factory()->agent()->create();
        $freeAgent = User::factory()->agent()->create();
        User::factory()->agent()->inactive()->create(); // inactive agents are ignored

        Ticket::factory()->count(3)->inProgress($busyAgent)->create();
        Ticket::factory()->count(1)->assignedTo($freeAgent)->create();
        Ticket::factory()->count(5)->closed($freeAgent)->create(); // closed tickets are not workload

        $ticket = Ticket::factory()->create();

        $this->actingAs($this->admin)->post(route('tickets.auto-assign', $ticket))->assertSessionHas('success');

        $this->assertSame($freeAgent->id, $ticket->fresh()->assigned_to);
        $this->assertSame(Ticket::STATUS_ASSIGNED, $ticket->fresh()->status);
    }

    public function test_auto_assign_uses_lowest_user_id_as_tie_breaker(): void
    {
        $firstAgent = User::factory()->agent()->create();
        $secondAgent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($this->admin)->post(route('tickets.auto-assign', $ticket));

        $this->assertSame($firstAgent->id, $ticket->fresh()->assigned_to);
        $this->assertNotSame($secondAgent->id, $ticket->fresh()->assigned_to);
    }

    public function test_auto_assign_without_active_agents_leaves_ticket_open(): void
    {
        User::factory()->agent()->inactive()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('tickets.auto-assign', $ticket))
            ->assertSessionHas('error', 'No eligible active support agent is available. The ticket remains Open.');

        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertSame(0, TicketActivity::count());
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    // ---- Reassignment ---------------------------------------------------------

    public function test_reassignment_keeps_status_and_notifies_new_agent(): void
    {
        $oldAgent = User::factory()->agent()->create();
        $newAgent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->inProgress($oldAgent)->create();

        $this->actingAs($this->admin)
            ->post(route('tickets.assign', $ticket), ['agent_id' => $newAgent->id])
            ->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame($newAgent->id, $ticket->assigned_to);
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $ticket->status);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id, 'action' => 'reassigned', 'old_value' => $oldAgent->name, 'new_value' => $newAgent->name,
        ]);
        $this->assertSame(1, $newAgent->notifications()->count());
        // V2: the previous agent gets ONE short in-app notice (no subject, no email).
        $this->assertSame(1, $oldAgent->notifications()->count());
        $this->assertSame('unassigned', $oldAgent->notifications()->first()->data['event_type']);
        $this->assertSame(0, $oldAgent->assignedTickets()->count(), 'ticket leaves the previous agent list');
    }

    public function test_resolved_or_closed_tickets_cannot_be_reassigned(): void
    {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->resolved($agent)->create();

        $this->actingAs($this->admin)
            ->post(route('tickets.assign', $ticket), ['agent_id' => $otherAgent->id])
            ->assertForbidden();
    }

    // ---- Lifecycle ------------------------------------------------------------

    public function test_full_happy_path_lifecycle(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create(['created_by' => $this->customer->id]);

        $this->actingAs($agent)->post(route('tickets.start', $ticket))->assertSessionHas('success');
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $ticket->fresh()->status);

        $this->actingAs($agent)
            ->post(route('tickets.resolve', $ticket), ['resolution_note' => 'Cleared the cache.'])
            ->assertSessionHas('success');
        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_RESOLVED, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertSame('Cleared the cache.', $ticket->resolution_note);
        $this->assertSame(1, $this->customer->notifications()->where('data->event_type', 'resolved')->count());

        $resolvedAt = $ticket->resolved_at->toDateTimeString();
        $this->actingAs($this->customer)->post(route('tickets.close', $ticket))->assertSessionHas('success');
        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->status);
        $this->assertSame($resolvedAt, $ticket->resolved_at->toDateTimeString(), 'resolved_at is preserved');
        $this->assertSame(1, $agent->notifications()->where('data->event_type', 'closed')->count());
    }

    public function test_invalid_status_transitions_are_rejected(): void
    {
        $agent = User::factory()->agent()->create();
        $assigned = Ticket::factory()->assignedTo($agent)->create(['created_by' => $this->customer->id]);
        $open = Ticket::factory()->create(['created_by' => $this->customer->id]);

        // Assigned -> Resolved skips "In Progress": not allowed.
        $this->actingAs($agent)->post(route('tickets.resolve', $assigned), ['resolution_note' => 'x'])->assertForbidden();
        // Open -> Closed: not allowed, even for the creator or admin.
        $this->actingAs($this->customer)->post(route('tickets.close', $open))->assertForbidden();
        $this->actingAs($this->admin)->post(route('tickets.close', $open))->assertForbidden();
        // Open -> In Progress without assignment: not allowed.
        $this->actingAs($this->admin)->post(route('tickets.start', $open))->assertForbidden();

        $this->assertSame(Ticket::STATUS_ASSIGNED, $assigned->fresh()->status);
        $this->assertSame(Ticket::STATUS_OPEN, $open->fresh()->status);
    }

    public function test_customer_cannot_start_or_resolve_tickets(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->inProgress($agent)->create(['created_by' => $this->customer->id]);

        $this->actingAs($this->customer)
            ->post(route('tickets.resolve', $ticket), ['resolution_note' => 'I fixed it myself'])
            ->assertForbidden();
    }

    public function test_resolution_note_is_required(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->inProgress($agent)->create();

        $this->actingAs($agent)
            ->post(route('tickets.resolve', $ticket), ['resolution_note' => ''])
            ->assertSessionHasErrors('resolution_note');

        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $ticket->fresh()->status);
    }

    public function test_creator_can_reopen_a_resolved_ticket(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->resolved($agent)->create([
            'created_by' => $this->customer->id,
            'resolution_note' => 'Restarted the server.',
            'due_at' => now()->subHour(), // original deadline already passed
        ]);

        $this->actingAs($this->customer)->post(route('tickets.reopen', $ticket))->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $ticket->status);
        $this->assertNull($ticket->resolved_at);
        $this->assertNull($ticket->resolution_note);
        $this->assertTrue($ticket->isOverdue(), 'original SLA is kept, so the reopened ticket is overdue');
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id, 'action' => 'reopened', 'old_value' => 'Previous resolution: Restarted the server.',
        ]);
        $this->assertSame(1, $agent->notifications()->where('data->event_type', 'reopened')->count());
    }

    public function test_reopen_requires_an_active_assigned_agent(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->resolved($agent)->create(['created_by' => $this->customer->id]);
        $agent->update(['is_active' => false]);

        $this->actingAs($this->customer)
            ->post(route('tickets.reopen', $ticket))
            ->assertSessionHasErrors('ticket');

        $this->assertSame(Ticket::STATUS_RESOLVED, $ticket->fresh()->status);
    }

    public function test_closed_tickets_are_read_only(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->closed($agent)->create(['created_by' => $this->customer->id]);

        $this->actingAs($this->customer)->post(route('tickets.comments.store', $ticket), ['message' => 'Hello?'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('tickets.edit', $ticket))->assertForbidden();
        $this->actingAs($this->admin)->post(route('tickets.reopen', $ticket))->assertForbidden();
        $this->actingAs($this->admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id])->assertForbidden();

        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
        $this->assertDatabaseCount('ticket_comments', 0);
    }
}
