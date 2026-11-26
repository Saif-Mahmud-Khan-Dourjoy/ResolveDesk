<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    use HasFactory;

    /** Cache key for the active-category dropdown list. */
    public const ACTIVE_CACHE_KEY = 'ticket:active-categories';

    /** Keep the dropdown list for one hour (it is also cleared on every category change). */
    public const ACTIVE_CACHE_TTL_SECONDS = 3600;

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Active categories for ticket form dropdowns (cache-aside pattern).
     *
     * Cache::remember() first looks for the key in the cache:
     *  - cache hit:  return the stored list, no categories query is run.
     *  - cache miss: run the closure, store the result for the TTL, return it.
     *
     * We cache a PLAIN ARRAY, not Eloquent objects: Laravel 13 refuses to
     * unserialize objects from the cache by default (config/cache.php
     * 'serializable_classes' => false, a security protection). hydrate()
     * turns the array rows back into Category models for the views.
     *
     * IMPORTANT: this list is only for DISPLAY. When a ticket is saved, the
     * controller checks the categories table directly, never this cache.
     */
    public static function activeForDropdown(): Collection
    {
        $rows = Cache::remember(self::ACTIVE_CACHE_KEY, self::ACTIVE_CACHE_TTL_SECONDS, function () {
            return Category::where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray();
        });

        return Category::hydrate($rows);
    }

    /** Remove the cached dropdown list so the next request rebuilds it. */
    public static function clearActiveCache(): void
    {
        Cache::forget(self::ACTIVE_CACHE_KEY);
    }
}
