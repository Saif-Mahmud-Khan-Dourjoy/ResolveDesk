<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * ONE reusable email for every ticket event.
 * It is not queued itself; the SendTicketEmail job is already running in the queue.
 */
class TicketUpdateMail extends Mailable
{
    public function __construct(
        public string $recipientName,
        public array $emailData,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[' . $this->emailData['ticket_number'] . '] ' . $this->emailData['event_title'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-update',
            with: [
                'recipientName' => $this->recipientName,
                'ticketNumber' => $this->emailData['ticket_number'],
                'ticketSubject' => $this->emailData['subject'],
                'eventTitle' => $this->emailData['event_title'],
                'eventMessage' => $this->emailData['event_message'],
                'statusLabel' => $this->emailData['status'],
                // The link only opens the ticket after login + TicketPolicy@view,
                // so the email itself never grants access.
                'ticketUrl' => route('tickets.show', $this->emailData['ticket_id']),
            ],
        );
    }
}
