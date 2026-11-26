<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test: loads the demo seed data and opens every page as each role.
 * This also proves the seeder works and that no page triggers a lazy-loading
 * (N+1) violation, because Model::preventLazyLoading() is on outside production.
 */
class PageRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeder_creates_the_expected_demo_data(): void
    {
        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->assertSame(4, User::where('role', 'agent')->count());
        $this->assertSame(5, User::where('role', 'customer')->count());
        $this->assertGreaterThanOrEqual(30, Ticket::count());
        $this->assertGreaterThan(0, Ticket::overdue()->count());
        $this->assertGreaterThan(0, Ticket::where('status', 'closed')->count());
    }

    public function test_admin_can_open_every_page(): void
    {
        $admin = User::where('email', 'admin@resolvedesk.test')->first();
        $ticket = Ticket::where('status', 'open')->first();
        $customer = User::where('role', 'customer')->first();
        $category = Category::first();

        $urls = [
            '/dashboard',
            '/tickets',
            '/tickets?status=in_progress&priority=high&overdue=1&search=a&date_from=2020-01-01&date_to=2030-01-01',
            '/tickets/create',
            route('tickets.show', $ticket),
            route('tickets.edit', $ticket),
            '/users',
            '/users/create',
            route('users.show', $customer),
            route('users.edit', $customer),
            '/categories',
            '/categories/create',
            route('categories.edit', $category),
            '/notifications',
        ];

        foreach ($urls as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        // Every ticket details page works for the admin (all statuses).
        foreach (Ticket::all() as $anyTicket) {
            $this->actingAs($admin)->get(route('tickets.show', $anyTicket))->assertOk();
        }
    }

    public function test_agent_can_open_their_pages(): void
    {
        $agent = User::where('email', 'sarah.agent@resolvedesk.test')->first();
        $ticket = $agent->assignedTickets()->first();

        foreach (['/dashboard', '/tickets', '/tickets/create', '/notifications', route('tickets.show', $ticket)] as $url) {
            $this->actingAs($agent)->get($url)->assertOk();
        }

        $this->actingAs($agent)->getJson('/notifications/feed')->assertOk();
    }

    public function test_customer_can_open_their_pages(): void
    {
        $customer = User::where('email', 'john.customer@example.com')->first();
        $ticket = $customer->createdTickets()->first();

        foreach (['/dashboard', '/tickets', '/tickets/create', '/notifications', route('tickets.show', $ticket)] as $url) {
            $this->actingAs($customer)->get($url)->assertOk();
        }
    }
}
