<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ticket creation, ticket numbers, SLA calculation, editing and filtering.
 */
class TicketManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_a_ticket(): void
    {
        Carbon::setTestNow('2026-01-10 10:00:00'); // freeze time so the SLA is predictable

        $customer = User::factory()->customer()->create();
        $someoneElse = User::factory()->customer()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($customer)->post('/tickets', [
            'subject' => 'Cannot export invoices',
            'description' => 'The export button does nothing.',
            'category_id' => $category->id,
            'priority' => 'high',
            'created_by' => $someoneElse->id, // must be ignored
            'status' => 'closed',             // must be ignored
        ]);

        $ticket = Ticket::first();
        $response->assertRedirect(route('tickets.show', $ticket));
        $response->assertSessionHas('success');

        $this->assertSame($customer->id, $ticket->created_by);
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->status);
        $this->assertNull($ticket->assigned_to);
        $this->assertSame('TK-' . str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT), $ticket->ticket_number);
        $this->assertSame('2026-01-10 18:00:00', $ticket->due_at->format('Y-m-d H:i:s')); // high = 8 hours

        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'action' => 'created', 'user_id' => $customer->id]);

        Carbon::setTestNow();
    }

    public function test_ticket_creation_validates_input(): void
    {
        $customer = User::factory()->customer()->create();

        $response = $this->actingAs($customer)->post('/tickets', [
            'subject' => '',
            'description' => '',
            'category_id' => 999,
            'priority' => 'urgent',
        ]);

        $response->assertSessionHasErrors(['subject', 'description', 'category_id', 'priority']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_inactive_category_is_rejected_for_new_tickets(): void
    {
        $customer = User::factory()->customer()->create();
        $inactiveCategory = Category::factory()->inactive()->create();

        $response = $this->actingAs($customer)->post('/tickets', [
            'subject' => 'Test', 'description' => 'Test', 'category_id' => $inactiveCategory->id, 'priority' => 'low',
        ]);

        $response->assertSessionHasErrors('category_id');
        $this->assertSame(0, Ticket::count());
    }

    public function test_ticket_numbers_are_unique(): void
    {
        $customer = User::factory()->customer()->create();
        $category = Category::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($customer)->post('/tickets', [
                'subject' => "Ticket {$i}", 'description' => 'x', 'category_id' => $category->id, 'priority' => 'low',
            ]);
        }

        $numbers = Ticket::pluck('ticket_number')->all();
        $this->assertCount(3, $numbers);
        $this->assertCount(3, array_unique($numbers));
    }

    public function test_sla_deadline_matches_priority(): void
    {
        $createdAt = Carbon::parse('2026-03-01 09:00:00');

        $this->assertSame('2026-03-03 09:00', Ticket::calculateDueAt($createdAt, 'low')->format('Y-m-d H:i'));      // 48h
        $this->assertSame('2026-03-02 09:00', Ticket::calculateDueAt($createdAt, 'medium')->format('Y-m-d H:i'));   // 24h
        $this->assertSame('2026-03-01 17:00', Ticket::calculateDueAt($createdAt, 'high')->format('Y-m-d H:i'));     // 8h
        $this->assertSame('2026-03-01 11:00', Ticket::calculateDueAt($createdAt, 'critical')->format('Y-m-d H:i')); // 2h
        $this->assertSame('2026-03-01 09:00:00', $createdAt->format('Y-m-d H:i:s'), 'the original date must not change');
    }

    public function test_overdue_is_calculated_dynamically(): void
    {
        $agent = User::factory()->agent()->create();
        $late = Ticket::factory()->inProgress($agent)->create(['due_at' => now()->subHour()]);
        $onTime = Ticket::factory()->inProgress($agent)->create(['due_at' => now()->addHour()]);
        $resolvedLate = Ticket::factory()->resolved($agent)->create(['due_at' => now()->subHour()]);

        $this->assertTrue($late->isOverdue());
        $this->assertFalse($onTime->isOverdue());
        $this->assertFalse($resolvedLate->isOverdue(), 'resolved tickets are never overdue');
        $this->assertEquals([$late->id], Ticket::overdue()->pluck('id')->all());
    }

    public function test_admin_priority_change_recalculates_sla_from_creation_time(): void
    {
        $admin = User::factory()->admin()->create();
        $ticket = Ticket::factory()->create([
            'priority' => 'low',
            'created_at' => '2026-02-01 08:00:00',
            'due_at' => '2026-02-03 08:00:00',
        ]);

        $this->actingAs($admin)->put(route('tickets.update', $ticket), [
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'category_id' => $ticket->category_id,
            'priority' => 'critical',
        ])->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame('critical', $ticket->priority);
        $this->assertSame('2026-02-01 10:00:00', $ticket->due_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'action' => 'priority_changed', 'old_value' => 'Low', 'new_value' => 'Critical']);
        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'action' => 'due_date_changed']);
    }

    public function test_editing_does_not_force_a_change_of_inactive_category(): void
    {
        $customer = User::factory()->customer()->create();
        $oldCategory = Category::factory()->inactive()->create();
        $otherInactive = Category::factory()->inactive()->create();
        $ticket = Ticket::factory()->create(['created_by' => $customer->id, 'category_id' => $oldCategory->id]);

        // Keeping the current inactive category is allowed.
        $this->actingAs($customer)->put(route('tickets.update', $ticket), [
            'subject' => 'Updated subject', 'description' => 'd', 'category_id' => $oldCategory->id, 'priority' => 'medium',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Updated subject', $ticket->fresh()->subject);

        // Switching to a different inactive category is not.
        $this->actingAs($customer)->put(route('tickets.update', $ticket), [
            'subject' => 'Again', 'description' => 'd', 'category_id' => $otherInactive->id, 'priority' => 'medium',
        ])->assertSessionHasErrors('category_id');
    }

    public function test_editing_without_changes_records_no_activity(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->create(['created_by' => $customer->id]);

        $this->actingAs($customer)->put(route('tickets.update', $ticket), [
            'subject' => $ticket->subject, 'description' => $ticket->description,
            'category_id' => $ticket->category_id, 'priority' => $ticket->priority,
        ])->assertSessionHas('success', 'No changes were made.');

        $this->assertSame(0, TicketActivity::count());
    }

    public function test_ticket_list_search_and_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $printer = Ticket::factory()->create(['subject' => 'Printer offline', 'priority' => 'high']);
        $invoice = Ticket::factory()->assignedTo($agent)->create(['subject' => 'Invoice missing', 'priority' => 'low', 'due_at' => now()->subDay()]);

        $this->actingAs($admin)->get('/tickets?search=Printer')->assertSee('Printer offline')->assertDontSee('Invoice missing');
        $this->actingAs($admin)->get('/tickets?priority=low')->assertSee('Invoice missing')->assertDontSee('Printer offline');
        $this->actingAs($admin)->get('/tickets?status=assigned')->assertSee('Invoice missing')->assertDontSee('Printer offline');
        $this->actingAs($admin)->get('/tickets?assigned_to=unassigned')->assertSee('Printer offline')->assertDontSee('Invoice missing');
        $this->actingAs($admin)->get('/tickets?assigned_to=' . $agent->id)->assertSee('Invoice missing')->assertDontSee('Printer offline');
        $this->actingAs($admin)->get('/tickets?overdue=1')->assertSee('Invoice missing')->assertDontSee('Printer offline');
        $this->actingAs($admin)->get('/tickets?category_id=' . $printer->category_id)->assertSee('Printer offline')->assertDontSee('Invoice missing');
        $this->actingAs($admin)->get('/tickets?date_from=' . now()->addDay()->toDateString())->assertDontSee('Printer offline');
        $this->actingAs($admin)->get('/tickets?search=' . $invoice->ticket_number)->assertSee('Invoice missing');
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/tickets?date_from=not-a-date')->assertSessionHasErrors('date_from');
        $this->actingAs($admin)->get('/tickets?status=deleted')->assertSessionHasErrors('status');
        $this->actingAs($admin)->getJson('/notifications/feed?since=garbage')->assertStatus(422);
    }
}
