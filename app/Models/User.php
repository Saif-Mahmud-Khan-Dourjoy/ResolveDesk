<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who can log in: administrator, support agent or customer.
 *
 * The Notifiable trait gives us $user->notify(...), $user->notifications()
 * and $user->unreadNotifications() for Laravel database notifications.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // ---- Role values (stored in users.role) ---------------------------------
    public const ROLE_ADMIN = 'admin';
    public const ROLE_AGENT = 'agent';
    public const ROLE_CUSTOMER = 'customer';

    // value => human label. Used for validation and dropdowns.
    public const ROLES = [
        self::ROLE_ADMIN => 'Administrator',
        self::ROLE_AGENT => 'Support Agent',
        self::ROLE_CUSTOMER => 'Customer',
    ];

    /**
     * Mass-assignable columns. Anything not listed here is ignored by
     * User::create($data) / $user->update($data) (mass-assignment protection).
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /** Never include these columns when the model is converted to an array/JSON. */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',     // Automatically bcrypt-hashes a plain password when it is set.
            'is_active' => 'boolean',
        ];
    }

    // ---- Relationships ------------------------------------------------------

    /** Tickets this user reported (tickets.created_by = users.id). */
    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by');
    }

    /** Tickets assigned to this agent (tickets.assigned_to = users.id). */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }

    public function ticketComments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    public function ticketActivities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }

    // ---- Simple role helpers ------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isAgent(): bool
    {
        return $this->role === self::ROLE_AGENT;
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
