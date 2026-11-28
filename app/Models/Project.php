<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * V2: a software product the company supports (e.g. HRMS, ERP, Payroll).
 *
 * Relationships:
 *   clients(): many-to-many through client_project (which organizations use it)
 *   agents():  many-to-many through project_user (which agents may be ASSIGNED its tickets)
 *   tickets(): one-to-many (tickets.project_id)
 *
 * Project membership only controls assignment eligibility. It does NOT let an
 * agent see every ticket of the project (see Ticket::scopeVisibleTo()).
 */
class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)->withTimestamps();
    }

    /** Support agents associated with this project (project_user pivot). */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** SQL: WHERE is_active = 1 */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** "HRMS - Human Resource Management" style label for dropdowns. */
    public function displayName(): string
    {
        return $this->code . ' - ' . $this->name;
    }
}
