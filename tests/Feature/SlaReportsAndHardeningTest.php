<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmail;
use App\Models\Client;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * V2 Phase 5/6: SLA scheduler (deduplicated reminders), reports + CSV export,
 * rate limiting and the readiness endpoint.
 */
class SlaReportsAndHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->admin()->create();
    }

    // ---- SLA scheduler ----------------------------------------------------------------

    public function test_overdue_assigned_ticket_notifies_the_agent_once(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create(['due_at' => now()->subHour()]);

        $this->artisan('tickets:check-sla')->assertSuccessful();
        $this->artisan('tickets:check-sla')->assertSuccessful(); // second run: nothing new

        $this->assertSame(1, $agent->notifications()->count());
        $this->assertSame('sla_breached', $agent->notifications()->first()->data['event_type']);
        $this->assertNotNull($ticket->fresh()->sla_notified_at);
        Queue::assertPushed(SendTicketEmail::class, 1);

        $activity = TicketActivity::where('action', 'sla_breached')->firstOrFail();
        $this->assertNull($activity->user_id, 'system activity has no user');
        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_overdue_unassigned_ticket_notifies_the_admins(): void
    {
        Ticket::factory()->create(['due_at' => now()->subMinutes(5)]);

        $this->artisan('tickets:check-sla')->assertSuccessful();

        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_tickets_that_are_not_overdue_or_resolved_are_ignored(): void
    {
        $agent = User::factory()->agent()->create();
        Ticket::factory()->assignedTo($agent)->create(['due_at' => now()->addHour()]);         // not yet due
        Ticket::factory()->resolved($agent)->create(['due_at' => now()->subDay()]);            // resolved: not overdue

        $this->artisan('tickets:check-sla')->assertSuccessful();

        $this->assertSame(0, $agent->notifications()->count());
        $this->assertSame(0, Ticket::whereNotNull('sla_notified_at')->count());
    }

    public function test_reopening_resets_the_reminder_so_a_new_breach_is_reported(): void
    {
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();
        $ticket = Ticket::factory()->resolved($agent)->create([
            'created_by' => $customer->id, 'due_at' => now()->subDay(), 'sla_notified_at' => now()->subHours(20),
        ]);

        $this->actingAs($customer)->post(route('tickets.reopen', $ticket));
        $this->assertNull($ticket->fresh()->sla_notified_at);

        $this->artisan('tickets:check-sla');
        $this->assertSame(1, $agent->notifications()->where('data->event_type', 'sla_breached')->count());
    }

    public function test_sla_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'tickets:check-sla'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // ---- Reports + CSV ----------------------------------------------------------------

    public function test_report_page_shows_filtered_tickets_and_sla_compliance(): void
    {
        $agent = User::factory()->agent()->create();
        $project = Project::factory()->create(['code' => 'HRMS']);
        Ticket::factory()->resolved($agent)->create(['project_id' => $project->id, 'subject' => 'Leave bug', 'due_at' => now()->addHour()]);
        Ticket::factory()->create(['subject' => 'Global printer']);

        $this->actingAs($this->admin)->get(route('reports.index', ['project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Leave bug')
            ->assertDontSee('Global printer')
            ->assertSee('100%'); // 1 of 1 resolved within SLA
    }

    public function test_csv_export_respects_filters_and_blocks_formula_injection(): void
    {
        $client = Client::factory()->create(['code' => 'ACME']);
        Ticket::factory()->create(['client_id' => $client->id, 'subject' => '=HYPERLINK("http://evil.example","click")']);
        Ticket::factory()->create(['subject' => 'Not for this client']);

        $response = $this->actingAs($this->admin)->get(route('reports.export', ['client_id' => $client->id]));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv, 'formula is neutralised with a leading quote');
        $this->assertStringNotContainsString('Not for this client', $csv);
        $this->assertStringContainsString('ACME', $csv);
    }

    public function test_only_admins_can_open_reports_or_export(): void
    {
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        foreach ([$agent, $customer] as $user) {
            $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
            $this->actingAs($user)->get(route('reports.export'))->assertForbidden();
        }
    }

    // ---- Rate limiting and health -----------------------------------------------------

    public function test_csv_export_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->admin)->get(route('reports.export'))->assertOk();
        }

        $this->actingAs($this->admin)->get(route('reports.export'))->assertStatus(429);
    }

    public function test_notification_feed_is_rate_limited_per_user(): void
    {
        $user = User::factory()->agent()->create();

        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($user)->getJson(route('notifications.feed'));
        }

        $this->actingAs($user)->getJson(route('notifications.feed'))->assertStatus(429);
        // Another user is not affected.
        $this->actingAs($this->admin)->getJson(route('notifications.feed'))->assertOk();
    }

    public function test_readiness_endpoint_reports_ok_without_details(): void
    {
        $this->getJson(route('health.ready'))
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_responses_carry_a_request_id(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertHeader('X-Request-Id');
    }
}
