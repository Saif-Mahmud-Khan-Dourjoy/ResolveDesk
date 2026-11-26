<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmail;
use App\Mail\TicketUpdateMail;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Queued email jobs: dispatched after commit, never after rollback,
 * and email failures never undo a committed ticket change.
 */
class QueueAndEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_queues_one_email_to_the_assigned_agent(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create(['email' => 'agent@resolvedesk.test']);
        $ticket = Ticket::factory()->create();

        $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id]);

        Queue::assertPushed(SendTicketEmail::class, function (SendTicketEmail $job) use ($ticket) {
            return $job->recipientEmail === 'agent@resolvedesk.test'
                && $job->emailData['ticket_number'] === $ticket->ticket_number
                && $job->emailData['status'] === 'Assigned';
        });
        Queue::assertPushed(SendTicketEmail::class, 1);
    }

    public function test_new_ticket_notifies_and_emails_every_active_admin(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create(['email' => 'admin@resolvedesk.test']);
        $inactiveAdmin = User::factory()->admin()->inactive()->create();
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();
        $category = Category::factory()->create();

        $this->actingAs($customer)->post('/tickets', [
            'subject' => 'VAT is wrong', 'description' => 'x', 'category_id' => $category->id, 'priority' => 'high',
        ]);

        $ticket = Ticket::first();
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('created', $admin->notifications()->first()->data['event_type']);
        $this->assertSame($ticket->ticket_number, $admin->notifications()->first()->data['ticket_number']);
        $this->assertSame(0, $inactiveAdmin->notifications()->count(), 'inactive admins are skipped');
        $this->assertSame(0, $agent->notifications()->count(), 'agents are not told about unassigned tickets');
        $this->assertSame(0, $customer->notifications()->count(), 'no notification about your own action');

        Queue::assertPushed(SendTicketEmail::class, 1);
        Queue::assertPushed(SendTicketEmail::class, fn ($job) => $job->recipientEmail === 'admin@resolvedesk.test'
            && $job->emailData['status'] === 'Open');
    }

    public function test_admin_creating_a_ticket_is_not_notified_about_it(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)->post('/tickets', [
            'subject' => 'Internal task', 'description' => 'x', 'category_id' => $category->id, 'priority' => 'low',
        ]);

        $this->assertSame(0, $admin->notifications()->count());
        $this->assertSame(1, $otherAdmin->notifications()->count());
        Queue::assertPushed(SendTicketEmail::class, 1);
    }

    public function test_resolution_emails_the_creator_but_closure_sends_no_email(): void
    {
        Queue::fake();
        $customer = User::factory()->customer()->create(['email' => 'customer@example.com']);
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->inProgress($agent)->create(['created_by' => $customer->id]);

        $this->actingAs($agent)->post(route('tickets.resolve', $ticket), ['resolution_note' => 'Done']);
        Queue::assertPushed(SendTicketEmail::class, fn ($job) => $job->recipientEmail === 'customer@example.com');

        $this->actingAs($customer)->post(route('tickets.close', $ticket));
        Queue::assertPushed(SendTicketEmail::class, 1); // still only the resolution email
    }

    public function test_no_email_job_is_dispatched_when_the_transaction_rolls_back(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        try {
            // An outer transaction that fails AFTER the assignment code has run.
            DB::transaction(function () use ($ticket, $agent, $admin) {
                app(TicketService::class)->assignAgent($ticket, $agent, $admin);
                throw new Exception('Something failed later in the same transaction');
            });
        } catch (Exception $exception) {
            // expected
        }

        Queue::assertNothingPushed();
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('ticket_activities', 0);
    }

    public function test_job_sends_the_ticket_update_mail(): void
    {
        Mail::fake();

        $job = new SendTicketEmail('agent@resolvedesk.test', 'Sarah', [
            'ticket_id' => 1,
            'ticket_number' => 'TK-000001',
            'subject' => 'Printer offline',
            'status' => 'Assigned',
            'event_title' => 'New ticket assigned',
            'event_message' => 'Ticket TK-000001 has been assigned to you.',
        ]);
        $job->handle();

        Mail::assertSent(TicketUpdateMail::class, function (TicketUpdateMail $mail) {
            return $mail->hasTo('agent@resolvedesk.test')
                && $mail->envelope()->subject === '[TK-000001] New ticket assigned';
        });
    }

    public function test_job_retry_configuration(): void
    {
        $job = new SendTicketEmail('a@b.test', 'A', []);

        $this->assertSame(3, $job->tries);
        $this->assertSame(10, $job->backoff);
    }

    public function test_email_template_renders_ticket_information(): void
    {
        $mail = new TicketUpdateMail('Sarah Ahmed', [
            'ticket_id' => 5,
            'ticket_number' => 'TK-000005',
            'subject' => 'Printer offline',
            'status' => 'Resolved',
            'event_title' => 'Ticket resolved',
            'event_message' => 'Your ticket has been resolved.',
        ]);

        $html = $mail->render();

        $this->assertStringContainsString('Hello Sarah Ahmed', $html);
        $this->assertStringContainsString('TK-000005', $html);
        $this->assertStringContainsString('Resolved', $html);
        $this->assertStringContainsString(route('tickets.show', 5), $html);
    }

    public function test_email_failure_does_not_reverse_the_committed_assignment(): void
    {
        // phpunit.xml uses the "sync" queue, so the job runs immediately after commit.
        // Make sending the email blow up:
        Mail::shouldReceive('to')->andThrow(new Exception('SMTP server unavailable'));

        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $response = $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id]);

        $response->assertSessionHas('success'); // the user is not told the assignment failed
        $this->assertSame($agent->id, $ticket->fresh()->assigned_to);
        $this->assertSame(1, $agent->notifications()->count());
    }

    public function test_in_app_notification_works_without_a_queue_worker(): void
    {
        // Use the real database queue. No worker is running in tests, so the job stays pending.
        config(['queue.default' => 'database']);

        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id]);

        $this->assertSame(1, $agent->notifications()->count(), 'in-app notification exists immediately');
        $this->assertDatabaseCount('jobs', 1); // email waits in the jobs table for `php artisan queue:work`
        $this->assertStringContainsString('SendTicketEmail', DB::table('jobs')->value('payload'));
    }
}
