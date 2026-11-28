<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * V2: ONE small broadcast event: "user X has a new notification".
 *
 * - Sent on the PRIVATE channel "users.{id}". routes/channels.php allows only
 *   that user to subscribe, so nobody can listen to another user's channel.
 * - The payload is only the notification id. No ticket subject, description or
 *   internal note travels over the WebSocket. The browser then calls the normal
 *   authenticated JSON feed (/notifications/feed), which applies all the usual
 *   authorization, and renders the toast from that data.
 * - ShouldBroadcastNow = sent immediately to Reverb (not via the queue).
 *   TicketNotifier only fires it AFTER the database commit, inside try/catch,
 *   so a stopped Reverb server never breaks a ticket operation.
 */
class UserNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public string $notificationId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.' . $this->userId)];
    }

    /** Event name the browser listens for: .notification.created */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return ['notification_id' => $this->notificationId];
    }
}
