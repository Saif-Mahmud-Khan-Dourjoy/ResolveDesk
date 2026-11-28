<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cache-aside behaviour for the admin dashboard and the active category list.
 */
class CacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_creates_the_statistics_cache(): void
    {
        $admin = User::factory()->admin()->create();
        Ticket::factory()->count(2)->create();

        $this->assertFalse(Cache::has(TicketService::DASHBOARD_CACHE_KEY));

        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $this->assertTrue(Cache::has(TicketService::DASHBOARD_CACHE_KEY));
        $this->assertSame(2, Cache::get(TicketService::DASHBOARD_CACHE_KEY)['total']);
    }

    public function test_dashboard_reads_statistics_from_the_cache(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/dashboard');

        // Put a recognisable value into the cache: if the page shows it, it was a cache hit.
        $stats = Cache::get(TicketService::DASHBOARD_CACHE_KEY);
        $stats['status_counts']['closed'] = 987;
        Cache::put(TicketService::DASHBOARD_CACHE_KEY, $stats, 60);

        $this->actingAs($admin)->get('/dashboard')->assertSee('987');
    }

    public function test_ticket_creation_invalidates_dashboard_cache_and_stats_are_correct_again(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create();

        $this->actingAs($admin)->get('/dashboard');
        $this->assertSame(0, Cache::get(TicketService::DASHBOARD_CACHE_KEY)['total']);

        $this->actingAs($admin)->post('/tickets', [
            'request_token' => (string) Str::uuid(),
            'subject' => 'New', 'description' => 'New', 'category_id' => $category->id, 'priority' => 'low',
        ]);
        $this->assertFalse(Cache::has(TicketService::DASHBOARD_CACHE_KEY), 'cache was forgotten');

        $this->actingAs($admin)->get('/dashboard');
        $this->assertSame(1, Cache::get(TicketService::DASHBOARD_CACHE_KEY)['total']);
        $this->assertSame(1, Cache::get(TicketService::DASHBOARD_CACHE_KEY)['status_counts']['open']);
    }

    public function test_status_change_invalidates_dashboard_cache(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();
        Cache::put(TicketService::DASHBOARD_CACHE_KEY, ['stale' => true], 60);

        $this->actingAs($agent)->post(route('tickets.start', $ticket));

        $this->assertFalse(Cache::has(TicketService::DASHBOARD_CACHE_KEY));
    }

    public function test_active_categories_are_cached(): void
    {
        Category::factory()->create(['name' => 'Billing']);
        Category::factory()->inactive()->create(['name' => 'Old stuff']);

        $categories = Category::activeForDropdown();

        $this->assertSame(['Billing'], $categories->pluck('name')->all());
        $this->assertTrue(Cache::has(Category::ACTIVE_CACHE_KEY));
    }

    public function test_category_cache_works_with_the_real_database_cache_store(): void
    {
        // phpunit.xml uses the "array" store, which never serializes values.
        // The real app uses CACHE_STORE=database, so test that store explicitly.
        config(['cache.default' => 'database']);
        Cache::forget(Category::ACTIVE_CACHE_KEY);

        $customer = User::factory()->customer()->create();
        Category::factory()->create(['name' => 'Billing']);

        $this->actingAs($customer)->get('/tickets/create')->assertOk(); // cache miss: stores the list
        $this->actingAs($customer)->get('/tickets/create')->assertOk()->assertSee('Billing'); // cache hit

        $this->assertSame(['Billing'], Category::activeForDropdown()->pluck('name')->all());
        $this->assertInstanceOf(Category::class, Category::activeForDropdown()->first());
    }

    public function test_category_changes_invalidate_the_category_cache(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['name' => 'Billing']);
        Category::activeForDropdown();

        $this->actingAs($admin)->put(route('categories.update', $category), ['name' => 'Billing', 'is_active' => 0]);

        $this->assertFalse(Cache::has(Category::ACTIVE_CACHE_KEY));
        $this->assertCount(0, Category::activeForDropdown(), 'deactivated category disappears from the dropdown');

        $this->actingAs($admin)->post(route('categories.store'), ['name' => 'Refunds', 'is_active' => 1]);
        $this->assertFalse(Cache::has(Category::ACTIVE_CACHE_KEY));
        $this->assertSame(['Refunds'], Category::activeForDropdown()->pluck('name')->all());
    }

    public function test_validation_never_trusts_a_stale_category_cache(): void
    {
        $customer = User::factory()->customer()->create();
        $category = Category::factory()->inactive()->create();

        // Simulate stale cache that still lists the now-inactive category.
        Cache::put(Category::ACTIVE_CACHE_KEY, Category::all(['id', 'name'])->toArray(), 3600);

        $this->actingAs($customer)->post('/tickets', [
            'request_token' => (string) Str::uuid(),
            'subject' => 'x', 'description' => 'x', 'category_id' => $category->id, 'priority' => 'low',
        ])->assertSessionHasErrors('category_id');
    }
}
