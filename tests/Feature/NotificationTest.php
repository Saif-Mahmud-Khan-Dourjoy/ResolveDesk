<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Comment notifications (recipients, no duplicates, no self-notification)
 * and the JSON endpoints used by public/js/notifications.js.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $agent;

    private User $admin;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->customer = User::factory()->customer()->create();
        $this->agent = User::factory()->agent()->create();
        $this->admin = User::factory()->admin()->create();
        $this->ticket = Ticket::factory()->inProgress($this->agent)->create(['created_by' => $this->customer->id]);
    }

    public function test_creator_comment_notifies_only_the_assigned_agent(): void
    {
        $this->actingAs($this->customer)->post(route('tickets.comments.store', $this->ticket), ['message' => 'Any update?']);

        $this->assertSame(1, $this->agent->notifications()->count());
        $this->assertSame(0, $this->customer->notifications()->count(), 'no notification about your own comment');
        $this->assertSame(0, $this->admin->notifications()->count());
        Queue::assertNothingPushed(); // comments never send emails
    }

    public function test_agent_reply_notifies_the_creator(): void
    {
        $this->actingAs($this->agent)->post(route('tickets.comments.store', $this->ticket), ['message' => 'Working on it.']);

        $this->assertSame(1, $this->customer->notifications()->count());
        $this->assertSame(0, $this->agent->notifications()->count());
        $this->assertSame('comment', $this->customer->notifications()->first()->data['event_type']);
    }

    public function test_admin_comment_notifies_creator_and_agent(): void
    {
        $this->actingAs($this->admin)->post(route('tickets.comments.store', $this->ticket), ['message' => 'Escalating.']);

        $this->assertSame(1, $this->customer->notifications()->count());
        $this->assertSame(1, $this->agent->notifications()->count());
        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_no_duplicate_when_creator_is_also_the_assigned_agent(): void
    {
        $ticket = Ticket::factory()->inProgress($this->agent)->create(['created_by' => $this->agent->id]);

        $this->actingAs($this->admin)->post(route('tickets.comments.store', $ticket), ['message' => 'Note.']);

        $this->assertSame(1, $this->agent->notifications()->count());
    }

    public function test_inactive_recipient_is_skipped(): void
    {
        $this->customer->update(['is_active' => false]);

        $this->actingAs($this->agent)->post(route('tickets.comments.store', $this->ticket), ['message' => 'Hello']);

        $this->assertSame(0, $this->customer->notifications()->count());
        $this->assertDatabaseCount('ticket_comments', 1);
    }

    public function test_feed_returns_unread_count_and_latest_five_notifications(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->agent->notify(new TicketNotification($this->ticket, 'comment', "Title {$i}", "Message {$i}"));
        }
        $this->customer->notify(new TicketNotification($this->ticket, 'comment', 'Not yours', 'Secret'));

        $response = $this->actingAs($this->agent)->getJson(route('notifications.feed'));

        $response->assertOk()
            ->assertJsonPath('unread_count', 7)
            ->assertJsonCount(5, 'notifications')
            ->assertJsonStructure(['unread_count', 'server_time', 'new_notifications', 'notifications' => [
                ['id', 'title', 'message', 'ticket_number', 'ticket_url', 'event_type', 'is_read', 'time_ago'],
            ]])
            ->assertJsonPath('notifications.0.ticket_number', $this->ticket->ticket_number)
            ->assertDontSee('Secret');
    }

    public function test_feed_with_since_returns_new_notifications(): void
    {
        $this->travel(-10)->minutes();
        $this->agent->notify(new TicketNotification($this->ticket, 'comment', 'Old one', 'old'));
        $this->travelBack();

        $since = now()->subMinute()->toIso8601String();
        $this->agent->notify(new TicketNotification($this->ticket, 'assigned', 'Fresh one', 'new'));

        $response = $this->actingAs($this->agent)->getJson(route('notifications.feed', ['since' => $since]));

        $response->assertJsonCount(1, 'new_notifications')
            ->assertJsonPath('new_notifications.0.title', 'Fresh one');
    }

    public function test_mark_single_notification_as_read(): void
    {
        $this->agent->notify(new TicketNotification($this->ticket, 'comment', 'A', 'a'));
        $this->agent->notify(new TicketNotification($this->ticket, 'comment', 'B', 'b'));
        $notification = $this->agent->notifications()->first();

        $this->actingAs($this->agent)
            ->postJson(route('notifications.read', $notification->id))
            ->assertOk()
            ->assertJson(['success' => true, 'unread_count' => 1]);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_notifications_as_read(): void
    {
        $this->agent->notify(new TicketNotification($this->ticket, 'comment', 'A', 'a'));
        $this->agent->notify(new TicketNotification($this->ticket, 'comment', 'B', 'b'));

        $this->actingAs($this->agent)->postJson(route('notifications.read-all'))->assertJson(['unread_count' => 0]);

        $this->assertSame(0, $this->agent->unreadNotifications()->count());
    }

    public function test_user_cannot_read_someone_elses_notification(): void
    {
        $this->customer->notify(new TicketNotification($this->ticket, 'comment', 'Private', 'x'));
        $customersNotification = $this->customer->notifications()->first();

        $this->actingAs($this->agent)
            ->postJson(route('notifications.read', $customersNotification->id))
            ->assertNotFound();

        $this->assertNull($customersNotification->fresh()->read_at);
    }

    public function test_history_page_with_read_filter(): void
    {
        $this->agent->notify(new TicketNotification($this->ticket, 'comment', 'Unread title', 'u'));
        $this->agent->notify(new TicketNotification($this->ticket, 'comment', 'Read title', 'r'));
        $this->agent->notifications()->where('data->title', 'Read title')->update(['read_at' => now()]);

        $this->actingAs($this->agent)->get('/notifications?filter=unread')->assertSee('Unread title')->assertDontSee('Read title');
        $this->actingAs($this->agent)->get('/notifications?filter=read')->assertSee('Read title')->assertDontSee('Unread title');
    }

    public function test_opening_from_history_marks_read_and_redirects_to_ticket(): void
    {
        $this->agent->notify(new TicketNotification($this->ticket, 'assigned', 'A', 'a'));
        $notification = $this->agent->notifications()->first();

        $this->actingAs($this->agent)
            ->post(route('notifications.read', $notification->id), ['open_ticket' => 1])
            ->assertRedirect(route('tickets.show', $this->ticket));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_feed_requires_authentication(): void
    {
        $this->getJson(route('notifications.feed'))->assertUnauthorized();
    }
}
