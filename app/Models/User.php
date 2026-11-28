<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    // client_id is NOT fillable: UserController sets it explicitly after its business checks.

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
            'client_id' => 'integer',
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

    /** V2: the client organization of a customer (NULL = standalone customer, admin or agent). */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** V2: projects a support agent supports (project_user pivot). Controls assignment eligibility. */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
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

    /** Admins and agents work for the software company; they may see internal notes. */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isAgent();
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
