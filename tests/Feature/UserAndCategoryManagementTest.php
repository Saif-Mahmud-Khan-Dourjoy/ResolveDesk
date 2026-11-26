<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * User management business rules and category CRUD rules.
 */
class UserAndCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ---- Users ----------------------------------------------------------------

    public function test_admin_can_create_a_user_with_a_hashed_password(): void
    {
        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'New Agent',
            'email' => 'new.agent@resolvedesk.test',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'agent',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'new.agent@resolvedesk.test')->first();
        $this->assertSame('agent', $user->role);
        $this->assertNotSame('secret123', $user->password);
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'X', 'email' => 'taken@example.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'role' => 'customer',
        ])->assertSessionHasErrors('email');
    }

    public function test_password_is_optional_when_editing(): void
    {
        $user = User::factory()->customer()->create();
        $oldHash = $user->password;

        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name' => 'Renamed', 'email' => $user->email, 'password' => '', 'password_confirmation' => '',
            'role' => 'customer', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Renamed', $user->name);
        $this->assertSame($oldHash, $user->password);
    }

    public function test_admin_cannot_deactivate_or_demote_themselves(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('users.toggle-active', $this->admin))
            ->assertSessionHasErrors('is_active');

        $this->actingAs($this->admin)->put(route('users.update', $this->admin), [
            'name' => $this->admin->name, 'email' => $this->admin->email, 'role' => 'agent', 'is_active' => 1,
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_another_admin_can_be_deactivated_when_an_active_admin_remains(): void
    {
        $secondAdmin = User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->patch(route('users.toggle-active', $secondAdmin))
            ->assertSessionHas('success');

        $this->assertFalse($secondAdmin->fresh()->is_active);
    }

    public function test_agent_with_unfinished_tickets_cannot_be_deactivated_or_demoted(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->inProgress($agent)->create();

        $this->actingAs($this->admin)
            ->patch(route('users.toggle-active', $agent))
            ->assertSessionHasErrors('is_active');

        $this->actingAs($this->admin)->put(route('users.update', $agent), [
            'name' => $agent->name, 'email' => $agent->email, 'role' => 'customer', 'is_active' => 1,
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue($agent->fresh()->is_active);

        // After the ticket is closed, deactivation is allowed.
        // (status is not mass-assignable, so it is set directly instead of with update())
        $ticket->status = Ticket::STATUS_CLOSED;
        $ticket->save();
        $this->actingAs($this->admin)->patch(route('users.toggle-active', $agent))->assertSessionHas('success');
        $this->assertFalse($agent->fresh()->is_active);
    }

    public function test_user_search_and_filters(): void
    {
        User::factory()->agent()->create(['name' => 'Agent Alpha']);
        User::factory()->customer()->inactive()->create(['name' => 'Customer Beta']);

        $this->actingAs($this->admin)->get('/users?search=Alpha')->assertSee('Agent Alpha')->assertDontSee('Customer Beta');
        $this->actingAs($this->admin)->get('/users?role=customer')->assertSee('Customer Beta')->assertDontSee('Agent Alpha');
        $this->actingAs($this->admin)->get('/users?status=inactive')->assertSee('Customer Beta')->assertDontSee('Agent Alpha');
    }

    // ---- Categories -------------------------------------------------------------

    public function test_category_name_must_be_unique(): void
    {
        Category::factory()->create(['name' => 'Billing']);

        $this->actingAs($this->admin)
            ->post(route('categories.store'), ['name' => 'Billing'])
            ->assertSessionHasErrors('name');
    }

    public function test_category_used_by_tickets_cannot_be_deleted(): void
    {
        $category = Category::factory()->create();
        Ticket::factory()->create(['category_id' => $category->id]);

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $category))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_unused_category_can_be_deleted(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin)->delete(route('categories.destroy', $category))->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_inactive_category_tickets_remain_viewable(): void
    {
        $customer = User::factory()->customer()->create();
        $category = Category::factory()->inactive()->create(['name' => 'Legacy App']);
        $ticket = Ticket::factory()->create(['created_by' => $customer->id, 'category_id' => $category->id]);

        $this->actingAs($customer)->get(route('tickets.show', $ticket))->assertOk()->assertSee('Legacy App');
    }
}
