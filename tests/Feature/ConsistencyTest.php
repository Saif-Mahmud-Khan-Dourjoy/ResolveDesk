<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\TicketAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * V2 Phase 3: optimistic locking (version), idempotent ticket creation,
 * the assignment cache lock and duplicate-operation protection.
 *
 * These tests run requests one after another. They prove the RULES (stale
 * version rejected, same token = one ticket), not true parallel execution;
 * see MySqlConcurrencyTest for checks with two real database connections.
 */
class ConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->customer()->create();
    }

    // ---- Optimistic locking -----------------------------------------------------

    public function test_edit_with_current_version_succeeds_and_increments_version(): void
    {
        $ticket = Ticket::factory()->create(['created_by' => $this->customer->id]);
        $this->assertSame(1, $ticket->fresh()->version);

        $this->actingAs($this->customer)->put(route('tickets.update', $ticket), [
            'subject' => 'New subject', 'description' => $ticket->description,
            'category_id' => $ticket->category_id, 'priority' => $ticket->priority, 'version' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $ticket->fresh()->version);
        $this->assertSame('New subject', $ticket->fresh()->subject);
    }

    public function test_stale_edit_is_rejected_and_does_not_overwrite(): void
    {
        $ticket = Ticket::factory()->create(['created_by' => $this->customer->id, 'subject' => 'Original']);

        // Admin saves first (version 1 -> 2).
        $this->actingAs($this->admin)->put(route('tickets.update', $ticket), [
            'subject' => 'Admin change', 'description' => $ticket->description,
            'category_id' => $ticket->category_id, 'priority' => $ticket->priority, 'version' => 1,
        ]);

        // Customer submits a form that was opened at version 1.
        $this->actingAs($this->customer)->put(route('tickets.update', $ticket), [
            'subject' => 'Customer change', 'description' => $ticket->description,
            'category_id' => $ticket->category_id, 'priority' => $ticket->priority, 'version' => 1,
        ])->assertSessionHas('error');

        $this->assertSame('Admin change', $ticket->fresh()->subject, 'no silent overwrite');
        $this->assertSame(2, $ticket->fresh()->version);
    }

    public function test_stale_version_returns_409_for_json_requests(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create(['version' => 3]);

        $this->actingAs($agent)
            ->postJson(route('tickets.start', $ticket), ['version' => 2])
            ->assertStatus(409);

        $this->assertSame(Ticket::STATUS_ASSIGNED, $ticket->fresh()->status);
    }

    public function test_every_workflow_mutation_increments_the_version(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create(['created_by' => $this->customer->id]);

        $this->actingAs($this->admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id, 'version' => 1]);
        $this->assertSame(2, $ticket->fresh()->version);

        $this->actingAs($agent)->post(route('tickets.start', $ticket), ['version' => 2]);
        $this->assertSame(3, $ticket->fresh()->version);

        $this->actingAs($agent)->post(route('tickets.resolve', $ticket), ['resolution_note' => 'Fixed', 'version' => 3]);
        $this->assertSame(4, $ticket->fresh()->version);

        $this->actingAs($this->customer)->post(route('tickets.reopen', $ticket), ['version' => 4]);
        $this->assertSame(5, $ticket->fresh()->version);

        $this->actingAs($agent)->post(route('tickets.resolve', $ticket), ['resolution_note' => 'Fixed again', 'version' => 5]);
        $this->actingAs($this->customer)->post(route('tickets.close', $ticket), ['version' => 6]);
        $this->assertSame(7, $ticket->fresh()->version);
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
    }

    public function test_double_submitted_resolve_creates_one_activity_and_one_notification(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->inProgress($agent)->create(['created_by' => $this->customer->id]);
        $form = ['resolution_note' => 'Fixed', 'version' => $ticket->version];

        $this->actingAs($agent)->post(route('tickets.resolve', $ticket), $form);
        $this->actingAs($agent)->post(route('tickets.resolve', $ticket), $form)->assertForbidden(); // second click: status is no longer In Progress

        $this->assertSame(1, TicketActivity::where('ticket_id', $ticket->id)->where('action', 'resolved')->count());
        $this->assertSame(1, $this->customer->notifications()->count());
    }

    // ---- Idempotent ticket creation ---------------------------------------------

    public function test_same_request_token_creates_only_one_ticket(): void
    {
        $category = Category::factory()->create();
        $form = [
            'subject' => 'Cannot log in', 'description' => 'x', 'category_id' => $category->id,
            'priority' => 'high', 'request_token' => (string) Str::uuid(),
        ];

        $first = $this->actingAs($this->customer)->post('/tickets', $form);
        $second = $this->actingAs($this->customer)->post('/tickets', $form); // browser retry / double click

        $this->assertSame(1, Ticket::count());
        $ticket = Ticket::first();
        $first->assertRedirect(route('tickets.show', $ticket));
        $second->assertRedirect(route('tickets.show', $ticket));
        $second->assertSessionHas('success', "This form was already submitted. Showing ticket {$ticket->ticket_number}.");
        $this->assertSame(1, TicketActivity::where('action', 'created')->count(), 'no duplicate activity');
        $this->assertSame(1, $this->admin->notifications()->count(), 'no duplicate notification');
    }

    public function test_reused_token_with_different_content_is_rejected(): void
    {
        $category = Category::factory()->create();
        $token = (string) Str::uuid();
        $base = ['description' => 'x', 'category_id' => $category->id, 'priority' => 'low', 'request_token' => $token];

        $this->actingAs($this->customer)->post('/tickets', $base + ['subject' => 'First']);
        $this->actingAs($this->customer)->post('/tickets', $base + ['subject' => 'Different'])
            ->assertSessionHas('error');

        $this->assertSame(1, Ticket::count());
    }

    public function test_request_token_is_scoped_to_the_creator(): void
    {
        $category = Category::factory()->create();
        $form = ['subject' => 'Same', 'description' => 'x', 'category_id' => $category->id, 'priority' => 'low', 'request_token' => (string) Str::uuid()];
        $otherCustomer = User::factory()->customer()->create();

        $this->actingAs($this->customer)->post('/tickets', $form);
        $this->actingAs($otherCustomer)->post('/tickets', $form);

        $this->assertSame(2, Ticket::count());
    }

    public function test_ticket_creation_requires_a_request_token(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->customer)
            ->post('/tickets', ['subject' => 'x', 'description' => 'x', 'category_id' => $category->id, 'priority' => 'low'])
            ->assertSessionHasErrors('request_token');
    }

    // ---- Assignment cache lock ----------------------------------------------------

    public function test_assignment_waits_for_the_lock_and_reports_a_timeout(): void
    {
        config(['resolvedesk.assignment_lock.wait_seconds' => 1]);
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        // Simulate another request holding the assignment lock.
        $heldLock = Cache::lock(config('resolvedesk.assignment_lock.key'), 10);
        $this->assertTrue($heldLock->get());

        $this->actingAs($this->admin)->post(route('tickets.auto-assign', $ticket))
            ->assertSessionHasErrors('ticket');
        $this->assertNull($ticket->fresh()->assigned_to);

        $heldLock->release();

        $this->actingAs($this->admin)->post(route('tickets.auto-assign', $ticket))->assertSessionHasNoErrors();
        $this->assertSame($agent->id, $ticket->fresh()->assigned_to);
    }

    public function test_lock_is_released_after_a_failed_assignment(): void
    {
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->create();
        $service = app(TicketAssignmentService::class);

        try {
            $service->assignAgent($ticket, $customer, $this->admin); // invalid agent -> exception
        } catch (ValidationException) {
        }

        $lock = Cache::lock(config('resolvedesk.assignment_lock.key'), 10);
        $this->assertTrue($lock->get(), 'the lock must be free again');
        $lock->release();
    }
}
