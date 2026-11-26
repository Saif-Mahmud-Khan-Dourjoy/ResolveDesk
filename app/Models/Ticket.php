<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer support ticket.
 *
 * All allowed statuses, priorities, SLA hours and status transitions are
 * defined here, in ONE place, so they are easy to find and modify.
 */
class Ticket extends Model
{
    use HasFactory;

    // ---- Statuses -----------------------------------------------------------
    public const STATUS_OPEN = 'open';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_ASSIGNED => 'Assigned',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_RESOLVED => 'Resolved',
        self::STATUS_CLOSED => 'Closed',
    ];

    /**
     * Valid lifecycle transitions, keyed by the ACTION (button) name.
     *
     *   Assign         open        -> assigned     (handled by assignment, see TicketService)
     *   Start Working  assigned    -> in_progress
     *   Resolve        in_progress -> resolved
     *   Close          resolved    -> closed
     *   Reopen         resolved    -> in_progress
     *   Closed tickets have no transitions: they are read-only.
     *
     * Any other status change is rejected. The general edit form never changes status.
     */
    public const TRANSITIONS = [
        'start' => ['from' => self::STATUS_ASSIGNED, 'to' => self::STATUS_IN_PROGRESS],
        'resolve' => ['from' => self::STATUS_IN_PROGRESS, 'to' => self::STATUS_RESOLVED],
        'close' => ['from' => self::STATUS_RESOLVED, 'to' => self::STATUS_CLOSED],
        'reopen' => ['from' => self::STATUS_RESOLVED, 'to' => self::STATUS_IN_PROGRESS],
    ];

    /** Statuses that still need work. Only these can be "overdue". */
    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
    ];

    /** Statuses counted as an agent's current workload for auto-assignment. */
    public const WORKLOAD_STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
    ];

    // ---- Priorities and SLA -------------------------------------------------
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    public const PRIORITIES = [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_MEDIUM => 'Medium',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_CRITICAL => 'Critical',
    ];

    /** SLA: hours allowed to resolve a ticket, per priority (continuous calendar hours). */
    public const SLA_HOURS = [
        self::PRIORITY_LOW => 48,
        self::PRIORITY_MEDIUM => 24,
        self::PRIORITY_HIGH => 8,
        self::PRIORITY_CRITICAL => 2,
    ];

    /**
     * Only these columns may be filled from user input with create()/update().
     * ticket_number, created_by, assigned_to, status, due_at, resolved_at and
     * resolution_note are set explicitly by TicketService, never from a form.
     */
    protected $fillable = [
        'subject',
        'description',
        'category_id',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            // Integer casts make comparisons like $ticket->created_by === $user->id reliable.
            'category_id' => 'integer',
            'created_by' => 'integer',
            'assigned_to' => 'integer',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    // ---- Relationships ------------------------------------------------------

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Nullable relationship: returns null while the ticket is unassigned. */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('created_at')->orderBy('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class)->orderBy('created_at')->orderBy('id');
    }

    // ---- Business helpers ---------------------------------------------------

    /**
     * SLA deadline = creation time + allowed hours for the priority.
     * Example: created 10:00, priority "high" (8h) => due 18:00.
     */
    public static function calculateDueAt(Carbon $createdAt, string $priority): Carbon
    {
        $hours = self::SLA_HOURS[$priority];

        return $createdAt->copy()->addHours($hours);
    }

    /** Builds the display number from the database id: 7 => "TK-000007". */
    public static function makeTicketNumber(int $id): string
    {
        return 'TK-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Is the lifecycle action ('start', 'resolve', 'close', 'reopen') valid
     * for the ticket's CURRENT status? Example: canPerform('close') is true
     * only while the status is "resolved".
     */
    public function canPerform(string $action): bool
    {
        return $this->status === self::TRANSITIONS[$action]['from'];
    }

    /**
     * Overdue is calculated live (never stored or cached):
     * still active AND the deadline has passed.
     */
    public function isOverdue(): bool
    {
        if (! in_array($this->status, self::ACTIVE_STATUSES, true)) {
            return false;
        }

        return now()->greaterThan($this->due_at);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? $this->priority;
    }

    // ---- Query scopes -------------------------------------------------------
    // Usage: Ticket::overdue()->count();  Ticket::visibleTo($user)->paginate();

    /** SQL: WHERE status IN ('open','assigned','in_progress') AND due_at < NOW() */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES)->where('due_at', '<', now());
    }

    /**
     * Tickets a user is allowed to see in lists.
     * Admin: everything. Agent/customer: tickets they created OR are assigned to.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        // The closure adds brackets: WHERE (created_by = ? OR assigned_to = ?)
        return $query->where(function (Builder $subQuery) use ($user) {
            $subQuery->where('created_by', $user->id)
                ->orWhere('assigned_to', $user->id);
        });
    }
}
