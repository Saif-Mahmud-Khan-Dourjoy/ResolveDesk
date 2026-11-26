<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a ticket's audit trail (activity timeline).
 */
class TicketActivity extends Model
{
    public const ACTION_LABELS = [
        'created' => 'Created the ticket',
        'updated' => 'Edited the ticket',
        'assigned' => 'Assigned an agent',
        'reassigned' => 'Reassigned the ticket',
        'priority_changed' => 'Changed the priority',
        'due_date_changed' => 'SLA deadline recalculated',
        'status_changed' => 'Changed the status',
        'resolved' => 'Resolved the ticket',
        'reopened' => 'Reopened the ticket',
        'closed' => 'Closed the ticket',
    ];

    protected $fillable = [
        'ticket_id',
        'user_id',
        'action',
        'old_value',
        'new_value',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }
}
