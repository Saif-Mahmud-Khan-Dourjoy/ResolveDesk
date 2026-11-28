<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role middleware (role:admin) and ticket-level policy checks (TicketPolicy).
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_agent_cannot_open_admin_pages(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        $this->actingAs($customer)->get('/users')->assertForbidden();
        $this->actingAs($customer)->get('/categories')->assertForbidden();
        $this->actingAs($agent)->get('/users/create')->assertForbidden();
        $this->actingAs($agent)->get('/categories')->assertForbidden();
    }

    public function test_customer_cannot_view_another_customers_ticket(): void
    {
        $owner = User::factory()->customer()->create();
        $otherCustomer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->create(['created_by' => $owner->id]);

        $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs($otherCustomer)->get(route('tickets.show', $ticket))->assertForbidden();
    }

    public function test_agent_can_only_view_assigned_or_own_tickets(): void
    {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $assignedTicket = Ticket::factory()->assignedTo($agent)->create();
        $otherTicket = Ticket::factory()->assignedTo($otherAgent)->create();
        $ownTicket = Ticket::factory()->create(['created_by' => $agent->id]);

        $this->actingAs($agent)->get(route('tickets.show', $assignedTicket))->assertOk();
        $this->actingAs($agent)->get(route('tickets.show', $ownTicket))->assertOk();
        $this->actingAs($agent)->get(route('tickets.show', $otherTicket))->assertForbidden();
    }

    public function test_ticket_list_only_contains_visible_tickets(): void
    {
        $customer = User::factory()->customer()->create();
        $mine = Ticket::factory()->create(['created_by' => $customer->id, 'subject' => 'My printer is broken']);
        $notMine = Ticket::factory()->create(['subject' => 'Someone else secret issue']);

        $this->actingAs($customer)->get('/tickets')
            ->assertOk()
            ->assertSee($mine->subject)
            ->assertDontSee($notMine->subject);
    }

    public function test_non_admin_cannot_assign_by_posting_directly(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

        // Even with a hand-crafted form submission, the role:admin middleware blocks it.
        $this->actingAs($customer)
            ->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id])
            ->assertForbidden();

        $this->actingAs($agent)
            ->post(route('tickets.auto-assign', $ticket))
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_customer_can_edit_only_open_unassigned_own_tickets(): void
    {
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();
        $openTicket = Ticket::factory()->create(['created_by' => $customer->id]);
        $assignedTicket = Ticket::factory()->assignedTo($agent)->create(['created_by' => $customer->id]);

        $this->actingAs($customer)->get(route('tickets.edit', $openTicket))->assertOk();
        $this->actingAs($customer)->get(route('tickets.edit', $assignedTicket))->assertForbidden();
        $this->actingAs($customer)
            ->put(route('tickets.update', $assignedTicket), [
                'version' => $assignedTicket->fresh()->version,
                'subject' => 'Changed', 'description' => 'x', 'category_id' => $assignedTicket->category_id, 'priority' => 'low',
            ])
            ->assertForbidden();
    }

    public function test_agent_cannot_act_on_ticket_assigned_to_someone_else(): void
    {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($otherAgent)->create();

        $this->actingAs($agent)->post(route('tickets.start', $ticket))->assertForbidden();
        $this->actingAs($agent)->post(route('tickets.comments.store', $ticket), ['message' => 'Hi'])->assertForbidden();

        $this->assertSame(Ticket::STATUS_ASSIGNED, $ticket->fresh()->status);
    }
}
