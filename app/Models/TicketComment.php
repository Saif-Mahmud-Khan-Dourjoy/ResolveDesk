<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message on a ticket.
 *
 * V2 visibility:
 *   public   = reply visible to everyone who can view the ticket (customer included)
 *   internal = staff-only note (admins and agents). Customers never receive it in
 *              Blade, JSON, email, notifications, reports, broadcasts or downloads.
 */
class TicketComment extends Model
{
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_INTERNAL = 'internal';

    public const VISIBILITIES = [
        self::VISIBILITY_PUBLIC => 'Public reply',
        self::VISIBILITY_INTERNAL => 'Internal note',
    ];

    // ticket_id, user_id and visibility are set by TicketService, not taken from the form.
    protected $fillable = [
        'message',
    ];

    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function isInternal(): bool
    {
        return $this->visibility === self::VISIBILITY_INTERNAL;
    }

    /**
     * Only the comments this user may read.
     * SQL for a customer: ... AND visibility = 'public'
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isStaff()) {
            return $query;
        }

        return $query->where('visibility', self::VISIBILITY_PUBLIC);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
