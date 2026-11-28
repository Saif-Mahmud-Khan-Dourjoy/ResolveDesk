<?php

namespace App\Models;

use App\Exceptions\TicketConflictException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * Tickets that are unresolved OR could still be reopened (everything except Closed).
     * Used by safety rules: an agent/project/client relationship that such a ticket
     * depends on cannot be removed.
     */
    public const OPEN_OR_REOPENABLE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
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
     * ticket_number, created_by, assigned_to, status, due_at, resolved_at,
     * resolution_note, project_id, client_id, version, sla_notified_at and
     * request_token are set explicitly by TicketService, never from a form.
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
            'project_id' => 'integer',
            'client_id' => 'integer',
            'version' => 'integer',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'sla_notified_at' => 'datetime',
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

    /** V2: NULL project = global ticket (not tied to a product). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** V2: NULL client = internal ticket (no client organization). */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** V2: all files of this ticket (ticket-level and comment-level). */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->orderBy('id');
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

    /**
     * V2 optimistic locking check.
     * $expectedVersion is the version the user saw when the page was rendered
     * (hidden "version" field). null = the caller did not send one (no check).
     */
    public function ensureVersion(?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion !== $this->version) {
            throw TicketConflictException::staleVersion();
        }
    }

    /**
     * V2 assignment eligibility, always read from the database (never from cache):
     *   - the user must be an ACTIVE support agent, and
     *   - for a project ticket, the agent must be associated with that project.
     *   - for a global ticket, every active agent is eligible.
     *
     * SQL (project ticket):
     *   SELECT EXISTS(SELECT 1 FROM project_user WHERE project_id = ? AND user_id = ?)
     */
    public function canBeAssignedTo(User $agent): bool
    {
        if (! $agent->isAgent() || ! $agent->is_active) {
            return false;
        }

        if ($this->project_id === null) {
            return true;
        }

        return DB::table('project_user')
            ->where('project_id', $this->project_id)
            ->where('user_id', $agent->id)
            ->exists();
    }

    /** "Global" = not tied to a project. It does NOT mean public. */
    public function isGlobal(): bool
    {
        return $this->project_id === null;
    }

    /**
     * SLA outcome for Resolved/Closed tickets (null while still active).
     * true = resolved on or before the deadline.
     */
    public function resolvedWithinSla(): ?bool
    {
        if ($this->resolved_at === null) {
            return null;
        }

        return $this->resolved_at->lessThanOrEqualTo($this->due_at);
    }

    /** "3 hours from now" / "2 days ago" - for the SLA panel. */
    public function slaRemainingForHumans(): string
    {
        return $this->due_at->diffForHumans();
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

    /**
     * SLA summary for any ticket query, in ONE aggregate SQL query:
     *
     *   SELECT COUNT(*) AS total,
     *          SUM(status IN ('open','assigned','in_progress')) AS active,
     *          SUM(status IN (...active...) AND due_at < NOW()) AS overdue,
     *          SUM(resolved_at IS NOT NULL) AS resolved,
     *          SUM(resolved_at IS NOT NULL AND resolved_at <= due_at) AS resolved_within_sla
     *   FROM tickets WHERE ...filters of $query...
     *
     * SLA compliance % = resolved_within_sla / resolved * 100
     * (only Resolved/Closed tickets count; null when nothing is resolved yet,
     * so we never divide by zero).
     */
    public static function slaSummary(Builder $query): array
    {
        $activeList = "'" . implode("','", self::ACTIVE_STATUSES) . "'"; // constants, not user input

        $row = (clone $query)->toBase()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("COALESCE(SUM(status IN ({$activeList})), 0) AS active")
            ->selectRaw("COALESCE(SUM(status IN ({$activeList}) AND due_at < ?), 0) AS overdue", [now()])
            ->selectRaw('COALESCE(SUM(resolved_at IS NOT NULL), 0) AS resolved')
            ->selectRaw('COALESCE(SUM(resolved_at IS NOT NULL AND resolved_at <= due_at), 0) AS resolved_within_sla')
            ->first();

        $resolved = (int) $row->resolved;
        $withinSla = (int) $row->resolved_within_sla;

        return [
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'overdue' => (int) $row->overdue,
            'resolved' => $resolved,
            'resolved_within_sla' => $withinSla,
            'resolved_after_sla' => $resolved - $withinSla,
            'sla_compliance' => $resolved > 0 ? round($withinSla / $resolved * 100, 1) : null,
        ];
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
