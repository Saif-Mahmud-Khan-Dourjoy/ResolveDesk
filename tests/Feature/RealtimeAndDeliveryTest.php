<?php

namespace Tests\Feature;

use App\Events\UserNotificationCreated;
use App\Jobs\SendTicketEmail;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * V2 Phase 4: private channel authorization, broadcast-after-commit,
 * persistent notifications independent of real-time delivery, email duplicates.
 */
class RealtimeAndDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** Use the real Reverb (Pusher protocol) broadcaster for channel auth; no server is contacted. */
    private function useReverbBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1001',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        // Channels were registered on the boot-time (null) broadcaster; register them on this one.
        require base_path('routes/channels.php');
    }

    // ---- Private channel authorization ------------------------------------------

    public function test_user_can_subscribe_only_to_their_own_private_channel(): void
    {
        $this->useReverbBroadcaster();
        $alice = User::factory()->agent()->create();
        $bob = User::factory()->agent()->create();

        $this->actingAs($alice)
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.' . $alice->id])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($alice)
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.' . $bob->id])
            ->assertForbidden();
    }

    public function test_guest_cannot_authorize_a_private_channel(): void
    {
        $this->useReverbBroadcaster();
        $user = User::factory()->create();

        $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-users.' . $user->id])
            ->assertForbidden();
    }

    // ---- Broadcast after commit ----------------------------------------------------

    public function test_assignment_broadcasts_only_the_notification_id_to_the_agent(): void
    {
        Event::fake([UserNotificationCreated::class]);
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create(['subject' => 'Secret salary data issue']);

        $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id]);

        $notification = $agent->notifications()->first();
        Event::assertDispatched(UserNotificationCreated::class, function (UserNotificationCreated $event) use ($agent, $notification) {
            return $event->userId === $agent->id
                && $event->notificationId === $notification->id
                && $event->broadcastOn()[0]->name === 'private-users.' . $agent->id
                && $event->broadcastWith() === ['notification_id' => $notification->id]; // no subject or description
        });
    }

    public function test_no_broadcast_and_no_email_when_the_transaction_rolls_back(): void
    {
        Event::fake([UserNotificationCreated::class]);
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        try {
            DB::transaction(function () use ($ticket, $agent, $admin) {
                app(TicketAssignmentService::class)->assignAgent($ticket, $agent, $admin);
                throw new \RuntimeException('Simulated failure after assignment');
            });
        } catch (\RuntimeException) {
        }

        Event::assertNotDispatched(UserNotificationCreated::class);
        Queue::assertNothingPushed();
        $this->assertSame(0, $agent->notifications()->count());
        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_failed_broadcast_does_not_break_the_operation(): void
    {
        Queue::fake();
        // Point the broadcaster at a port where nothing listens: the HTTP call fails.
        $this->useReverbBroadcaster();
        config(['broadcasting.connections.reverb.options.port' => 1]);

        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id])
            ->assertSessionHas('success');

        $this->assertSame($agent->id, $ticket->fresh()->assigned_to);
        $this->assertSame(1, $agent->notifications()->count(), 'the notification is persisted anyway');
    }

    // ---- Email ------------------------------------------------------------------------

    public function test_email_job_carries_the_delivery_key_and_project(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->create();

        $this->actingAs($admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id]);

        $notificationId = $agent->notifications()->first()->id;
        Queue::assertPushed(SendTicketEmail::class, function (SendTicketEmail $job) use ($notificationId) {
            return $job->emailData['delivery_key'] === $notificationId
                && array_key_exists('project', $job->emailData);
        });
    }

    public function test_retried_email_job_does_not_send_twice(): void
    {
        Mail::fake();
        Cache::flush();
        $job = new SendTicketEmail('agent@example.test', 'Agent', [
            'ticket_id' => 1, 'ticket_number' => 'TK-000001', 'subject' => 'S', 'status' => 'Assigned',
            'project' => null, 'event_title' => 'T', 'event_message' => 'M', 'delivery_key' => 'abc-123',
        ]);

        $job->handle();
        $job->handle(); // the worker retries the same job (e.g. after a crash before acknowledging)

        Mail::assertSentCount(1);
    }

    public function test_worker_timeout_is_shorter_than_retry_after(): void
    {
        $job = new SendTicketEmail('a@example.test', 'A', []);

        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertSame(3, $job->tries);
    }
}
