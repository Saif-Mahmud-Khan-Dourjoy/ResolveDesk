<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Notification;

/**
 * ONE reusable in-app notification for every ticket event
 * (created, assigned, reassigned, unassigned, comment, internal_note, resolved,
 * reopened, closed, sla_breached). Created by TicketNotifier (app/Services).
 *
 * $user->notify(new TicketNotification(...)) inserts one row into the
 * "notifications" table immediately (it is NOT queued), so in-app
 * notifications work even when no queue worker is running.
 */
class TicketNotification extends Notification
{
    public function __construct(
        public Ticket $ticket,
        public string $eventType,   // e.g. "assigned", "comment", "resolved"
        public string $title,       // short heading shown in bold
        public string $message,     // one-line description
    ) {}

    /** Which channels to use. "database" = store in the notifications table. */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The array returned here is JSON-encoded into notifications.data.
     * notifications.js later reads these keys from the JSON feed.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'title' => $this->title,
            'message' => $this->message,
            'event_type' => $this->eventType,
        ];
    }
}
