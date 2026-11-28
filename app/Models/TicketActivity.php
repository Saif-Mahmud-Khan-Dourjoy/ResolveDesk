<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a ticket's audit trail (activity timeline).
 *
 * user_id NULL = the action was performed by the SYSTEM (e.g. the SLA scheduler).
 * is_internal = true hides the entry from customers (e.g. "internal note added").
 * Activities are only ever inserted, never edited (no update routes exist).
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
        'comment_added' => 'Added a public reply',
        'internal_note_added' => 'Added an internal note',
        'attachment_added' => 'Uploaded an attachment',
        'sla_breached' => 'SLA deadline breached',
    ];

    protected $fillable = [
        'ticket_id',
        'user_id',
        'action',
        'old_value',
        'new_value',
        'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    /**
     * Inserts one audit-trail row. $user = null means "performed by the system".
     * Called inside the same transaction as the ticket change it describes.
     */
    public static function record(Ticket $ticket, ?User $user, string $action, ?string $oldValue, ?string $newValue, bool $isInternal = false): self
    {
        return self::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user?->id,
            'action' => $action,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'is_internal' => $isInternal,
        ]);
    }

    /** Customers do not see internal entries. SQL: ... AND is_internal = 0 */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isStaff()) {
            return $query;
        }

        return $query->where('is_internal', false);
    }

    public function isSystem(): bool
    {
        return $this->user_id === null;
    }

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
