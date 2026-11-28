<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V2 Phase 1: projects, clients, customer organization mapping and the
 * project <-> client / project <-> agent associations.
 */
class ProjectAndClientTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ---- Project and client CRUD ---------------------------------------------

    public function test_admin_can_create_and_update_a_project(): void
    {
        $this->actingAs($this->admin)
            ->post(route('projects.store'), ['name' => 'Payroll Management', 'code' => 'pay-01', 'is_active' => '1'])
            ->assertRedirect();

        $project = Project::where('code', 'PAY-01')->firstOrFail(); // code stored in upper case
        $this->assertTrue($project->is_active);

        $this->actingAs($this->admin)
            ->put(route('projects.update', $project), ['name' => 'Payroll v2', 'code' => 'PAY-01']) // switch off = inactive
            ->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame('Payroll v2', $project->name);
        $this->assertFalse($project->is_active);
    }

    public function test_project_code_must_be_unique(): void
    {
        Project::factory()->create(['code' => 'HRMS']);

        $this->actingAs($this->admin)
            ->post(route('projects.store'), ['name' => 'Another', 'code' => 'hrms'])
            ->assertSessionHasErrors('code');
    }

    public function test_admin_can_create_and_update_a_client(): void
    {
        $this->actingAs($this->admin)
            ->post(route('clients.store'), ['name' => 'Acme Ltd', 'code' => 'acme', 'email' => 'it@acme.test', 'is_active' => '1'])
            ->assertRedirect();

        $client = Client::where('code', 'ACME')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('clients.update', $client), ['name' => 'Acme Group', 'code' => 'ACME', 'is_active' => '1'])
            ->assertRedirect(route('clients.show', $client));

        $this->assertSame('Acme Group', $client->fresh()->name);
    }

    public function test_non_admins_cannot_manage_projects_or_clients(): void
    {
        $agent = User::factory()->agent()->create();
        $customer = User::factory()->customer()->create();

        foreach ([$agent, $customer] as $user) {
            $this->actingAs($user)->get(route('projects.index'))->assertForbidden();
            $this->actingAs($user)->get(route('clients.index'))->assertForbidden();
            $this->actingAs($user)->post(route('projects.store'), ['name' => 'X', 'code' => 'X'])->assertForbidden();
        }
    }

    public function test_project_and_client_pages_render(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->create();
        $project->clients()->attach($client);

        $this->actingAs($this->admin)->get(route('projects.index'))->assertOk()->assertSee($project->code);
        $this->actingAs($this->admin)->get(route('projects.show', $project))->assertOk()->assertSee($client->name);
        $this->actingAs($this->admin)->get(route('clients.index'))->assertOk()->assertSee($client->code);
        $this->actingAs($this->admin)->get(route('clients.show', $client))->assertOk()->assertSee($project->code);
    }

    // ---- Project <-> client association ---------------------------------------

    public function test_admin_can_associate_clients_with_a_project(): void
    {
        $project = Project::factory()->create();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('projects.clients.update', $project), ['client_ids' => [$clientA->id, $clientB->id]])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$clientA->id, $clientB->id], $project->clients()->pluck('clients.id')->all());

        // Removing one client (no tickets depend on it).
        $this->actingAs($this->admin)
            ->put(route('projects.clients.update', $project), ['client_ids' => [$clientA->id]]);

        $this->assertSame([$clientA->id], $project->clients()->pluck('clients.id')->all());
    }

    public function test_inactive_client_cannot_be_newly_associated(): void
    {
        $project = Project::factory()->create();
        $inactiveClient = Client::factory()->inactive()->create();

        $this->actingAs($this->admin)
            ->put(route('projects.clients.update', $project), ['client_ids' => [$inactiveClient->id]])
            ->assertSessionHasErrors('client_ids');

        $this->assertSame(0, $project->clients()->count());
    }

    public function test_client_cannot_be_removed_while_it_has_open_tickets_in_the_project(): void
    {
        $project = Project::factory()->create();
        $client = Client::factory()->create();
        $project->clients()->attach($client);
        Ticket::factory()->create(['project_id' => $project->id, 'client_id' => $client->id]);

        $this->actingAs($this->admin)
            ->put(route('projects.clients.update', $project), ['client_ids' => []])
            ->assertSessionHasErrors('client_ids');

        $this->assertSame(1, $project->clients()->count());
    }

    public function test_client_can_be_removed_when_its_project_tickets_are_closed(): void
    {
        $agent = User::factory()->agent()->create();
        $project = Project::factory()->create();
        $client = Client::factory()->create();
        $project->clients()->attach($client);
        Ticket::factory()->closed($agent)->create(['project_id' => $project->id, 'client_id' => $client->id]);

        $this->actingAs($this->admin)
            ->put(route('projects.clients.update', $project), ['client_ids' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $project->clients()->count());
    }

    // ---- Project <-> agent association ----------------------------------------

    public function test_admin_can_associate_active_agents_only(): void
    {
        $project = Project::factory()->create();
        $agent = User::factory()->agent()->create();
        $inactiveAgent = User::factory()->agent()->inactive()->create();
        $customer = User::factory()->customer()->create();

        $this->actingAs($this->admin)
            ->put(route('projects.agents.update', $project), ['agent_ids' => [$agent->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame([$agent->id], $project->agents()->pluck('users.id')->all());

        foreach ([$inactiveAgent, $customer] as $invalidUser) {
            $this->actingAs($this->admin)
                ->put(route('projects.agents.update', $project), ['agent_ids' => [$agent->id, $invalidUser->id]])
                ->assertSessionHasErrors('agent_ids');
        }

        $this->assertSame([$agent->id], $project->agents()->pluck('users.id')->all());
    }

    public function test_agent_cannot_be_removed_while_responsible_for_project_tickets(): void
    {
        $project = Project::factory()->create();
        $agent = User::factory()->agent()->create();
        $project->agents()->attach($agent);
        Ticket::factory()->resolved($agent)->create(['project_id' => $project->id]); // reopenable

        $this->actingAs($this->admin)
            ->put(route('projects.agents.update', $project), ['agent_ids' => []])
            ->assertSessionHasErrors('agent_ids');

        $this->assertSame(1, $project->agents()->count());
    }

    // ---- Customer organization mapping ----------------------------------------

    public function test_admin_can_assign_a_customer_to_an_active_client(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'Nadia Customer',
            'email' => 'nadia@client.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_CUSTOMER,
            'is_active' => '1',
            'client_id' => $client->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($client->id, User::where('email', 'nadia@client.test')->value('client_id'));
    }

    public function test_agents_never_get_a_client(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->admin)->post(route('users.store'), [
            'name' => 'New Agent',
            'email' => 'agent.new@resolvedesk.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_AGENT,
            'is_active' => '1',
            'client_id' => $client->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'agent.new@resolvedesk.test')->value('client_id'));
    }

    public function test_customer_with_client_tickets_cannot_change_organization(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $customer = User::factory()->forClient($clientA)->create();
        Ticket::factory()->create(['created_by' => $customer->id, 'client_id' => $clientA->id]);

        $this->actingAs($this->admin)->put(route('users.update', $customer), [
            'name' => $customer->name,
            'email' => $customer->email,
            'role' => User::ROLE_CUSTOMER,
            'is_active' => '1',
            'client_id' => $clientB->id,
        ])->assertSessionHasErrors('client_id');

        $this->assertSame($clientA->id, $customer->fresh()->client_id);
    }
}
