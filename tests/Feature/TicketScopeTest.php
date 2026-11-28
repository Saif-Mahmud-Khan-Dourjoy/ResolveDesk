<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V2 Phase 2: global / project / client tickets, role-specific creation rules,
 * cross-client isolation and project-aware assignment eligibility.
 */
class TicketScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    private Client $acme;

    private Project $hrms;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->admin = User::factory()->admin()->create();
        $this->category = Category::factory()->create();
        $this->acme = Client::factory()->create(['code' => 'ACME']);
        $this->hrms = Project::factory()->create(['code' => 'HRMS']);
        $this->hrms->clients()->attach($this->acme);
    }

    private function ticketData(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Payslip shows wrong tax',
            'description' => 'Steps to reproduce...',
            'category_id' => $this->category->id,
            'priority' => 'medium',
            'request_token' => (string) Str::uuid(),
        ], $overrides);
    }

    // ---- Customer creation ------------------------------------------------------

    public function test_customer_can_create_a_global_ticket_and_client_is_derived_from_account(): void
    {
        $customer = User::factory()->forClient($this->acme)->create();
        $otherClient = Client::factory()->create();

        // A customer tries to inject another client id: it is ignored.
        $this->actingAs($customer)->post('/tickets', $this->ticketData(['client_id' => $otherClient->id]))
            ->assertSessionHasNoErrors();

        $ticket = Ticket::latest('id')->first();
        $this->assertNull($ticket->project_id, 'global ticket');
        $this->assertSame($this->acme->id, $ticket->client_id, 'client comes from the customer account');
    }

    public function test_customer_can_create_a_ticket_for_a_project_of_their_client(): void
    {
        $customer = User::factory()->forClient($this->acme)->create();

        $this->actingAs($customer)->post('/tickets', $this->ticketData(['project_id' => $this->hrms->id]))
            ->assertSessionHasNoErrors();

        $ticket = Ticket::latest('id')->first();
        $this->assertSame($this->hrms->id, $ticket->project_id);
        $this->assertSame($this->acme->id, $ticket->client_id);
    }

    public function test_customer_cannot_use_a_project_not_associated_with_their_client(): void
    {
        $customer = User::factory()->forClient($this->acme)->create();
        $erp = Project::factory()->create(); // not used by ACME

        $this->actingAs($customer)->post('/tickets', $this->ticketData(['project_id' => $erp->id]))
            ->assertSessionHasErrors('project_id');

        $this->assertSame(0, Ticket::count());
    }

    public function test_customer_cannot_use_an_inactive_project(): void
    {
        $customer = User::factory()->forClient($this->acme)->create();
        $this->hrms->update(['is_active' => false]);

        $this->actingAs($customer)->post('/tickets', $this->ticketData(['project_id' => $this->hrms->id]))
            ->assertSessionHasErrors('project_id');
    }

    public function test_standalone_customer_can_only_create_global_tickets(): void
    {
        $standalone = User::factory()->customer()->create(); // client_id NULL

        $this->actingAs($standalone)->post('/tickets', $this->ticketData(['project_id' => $this->hrms->id]))
            ->assertSessionHasErrors('project_id');

        $this->actingAs($standalone)->post('/tickets', $this->ticketData())->assertSessionHasNoErrors();
        $this->assertNull(Ticket::first()->client_id);
    }

    public function test_customer_of_inactive_client_cannot_create_tickets(): void
    {
        $customer = User::factory()->forClient($this->acme)->create();
        $this->acme->update(['is_active' => false]);

        $this->actingAs($customer)->get(route('tickets.create'))->assertForbidden();
        $this->actingAs($customer)->post('/tickets', $this->ticketData())->assertForbidden();
    }

    // ---- Admin and agent creation -----------------------------------------------

    public function test_admin_can_create_all_four_scope_combinations(): void
    {
        $combinations = [
            [null, null],                       // global internal
            [null, $this->acme->id],            // global client-related
            [$this->hrms->id, null],            // project internal
            [$this->hrms->id, $this->acme->id], // project + client
        ];

        foreach ($combinations as [$projectId, $clientId]) {
            $this->actingAs($this->admin)
                ->post('/tickets', $this->ticketData(['project_id' => $projectId, 'client_id' => $clientId]))
                ->assertSessionHasNoErrors();

            $ticket = Ticket::latest('id')->first();
            $this->assertSame($projectId, $ticket->project_id);
            $this->assertSame($clientId, $ticket->client_id);
            $this->assertSame($this->admin->id, $ticket->created_by, 'admin never impersonates a customer');
        }
    }

    public function test_admin_cannot_combine_a_client_with_a_project_it_does_not_use(): void
    {
        $otherClient = Client::factory()->create();

        $this->actingAs($this->admin)
            ->post('/tickets', $this->ticketData(['project_id' => $this->hrms->id, 'client_id' => $otherClient->id]))
            ->assertSessionHasErrors('project_id');
    }

    public function test_agent_creates_internal_tickets_only_for_supported_projects(): void
    {
        $agent = User::factory()->agent()->create();
        $this->hrms->agents()->attach($agent);
        $erp = Project::factory()->create();

        $this->actingAs($agent)->post('/tickets', $this->ticketData(['project_id' => $erp->id]))
            ->assertSessionHasErrors('project_id');

        $this->actingAs($agent)
            ->post('/tickets', $this->ticketData(['project_id' => $this->hrms->id, 'client_id' => $this->acme->id]))
            ->assertSessionHasNoErrors();

        $ticket = Ticket::latest('id')->first();
        $this->assertSame($this->hrms->id, $ticket->project_id);
        $this->assertNull($ticket->client_id, 'agents cannot create tickets on behalf of clients');
    }

    // ---- Dependent dropdown endpoint ------------------------------------------

    public function test_project_options_endpoint_returns_only_allowed_projects(): void
    {
        $erp = Project::factory()->create(['code' => 'ERP']);
        $customer = User::factory()->forClient($this->acme)->create();

        // Customer: own client's projects only, even when asking for everything.
        $this->actingAs($customer)->getJson(route('tickets.project-options'))
            ->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.id', $this->hrms->id);

        // Admin with a client: that client's projects. Without a client: all active projects.
        $this->actingAs($this->admin)->getJson(route('tickets.project-options', ['client_id' => $this->acme->id]))
            ->assertJsonCount(1, 'projects');
        $this->actingAs($this->admin)->getJson(route('tickets.project-options'))
            ->assertJsonCount(2, 'projects');

        $this->assertNotNull($erp);
    }

    // ---- Isolation ----------------------------------------------------------------

    public function test_customer_cannot_see_another_clients_ticket(): void
    {
        $globex = Client::factory()->create();
        $acmeCustomer = User::factory()->forClient($this->acme)->create();
        $globexCustomer = User::factory()->forClient($globex)->create();
        $globexTicket = Ticket::factory()->create(['created_by' => $globexCustomer->id, 'client_id' => $globex->id]);

        $this->actingAs($acmeCustomer)->get(route('tickets.show', $globexTicket))->assertForbidden();
        $this->actingAs($acmeCustomer)->get(route('tickets.index'))->assertDontSee($globexTicket->ticket_number);
    }

    public function test_project_membership_does_not_grant_access_to_project_tickets(): void
    {
        $agent = User::factory()->agent()->create();
        $this->hrms->agents()->attach($agent);
        $ticket = Ticket::factory()->create(['project_id' => $this->hrms->id]);

        $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertForbidden();
    }

    // ---- Assignment eligibility ---------------------------------------------------

    public function test_project_ticket_can_only_be_assigned_to_project_agents(): void
    {
        $member = User::factory()->agent()->create();
        $outsider = User::factory()->agent()->create();
        $this->hrms->agents()->attach($member);
        $ticket = Ticket::factory()->create(['project_id' => $this->hrms->id]);

        $this->actingAs($this->admin)->post(route('tickets.assign', $ticket), ['agent_id' => $outsider->id])
            ->assertSessionHasErrors('agent_id');
        $this->assertNull($ticket->fresh()->assigned_to);

        $this->actingAs($this->admin)->post(route('tickets.assign', $ticket), ['agent_id' => $member->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($member->id, $ticket->fresh()->assigned_to);
    }

    public function test_global_ticket_can_be_assigned_to_any_active_agent(): void
    {
        $agent = User::factory()->agent()->create(); // supports no project
        $ticket = Ticket::factory()->create();

        $this->actingAs($this->admin)->post(route('tickets.assign', $ticket), ['agent_id' => $agent->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($agent->id, $ticket->fresh()->assigned_to);
    }

    public function test_auto_assign_considers_only_project_agents_but_counts_all_workload(): void
    {
        $busyMember = User::factory()->agent()->create();
        $idleOutsider = User::factory()->agent()->create();
        $quietMember = User::factory()->agent()->create();
        $this->hrms->agents()->attach([$busyMember->id, $quietMember->id]);

        Ticket::factory()->count(2)->assignedTo($busyMember)->create();
        Ticket::factory()->assignedTo($quietMember)->create(); // workload from a GLOBAL ticket still counts

        $ticket = Ticket::factory()->create(['project_id' => $this->hrms->id]);

        $this->actingAs($this->admin)->post(route('tickets.auto-assign', $ticket));

        $this->assertSame($quietMember->id, $ticket->fresh()->assigned_to, 'idle outsider is not eligible');
        $this->assertNotNull($idleOutsider);
    }

    public function test_auto_assign_with_no_project_agent_leaves_ticket_open(): void
    {
        User::factory()->agent()->create(); // exists, but not in the project
        $ticket = Ticket::factory()->create(['project_id' => $this->hrms->id]);

        $this->actingAs($this->admin)->post(route('tickets.auto-assign', $ticket))
            ->assertSessionHas('error');

        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status);
    }

    // ---- Filters --------------------------------------------------------------------

    public function test_ticket_list_can_filter_global_and_project_tickets(): void
    {
        $global = Ticket::factory()->create(['subject' => 'Global printer issue']);
        $project = Ticket::factory()->create(['subject' => 'HRMS leave bug', 'project_id' => $this->hrms->id]);

        $this->actingAs($this->admin)->get(route('tickets.index', ['project_id' => 'global']))
            ->assertSee($global->subject)->assertDontSee($project->subject);

        $this->actingAs($this->admin)->get(route('tickets.index', ['project_id' => $this->hrms->id]))
            ->assertSee($project->subject)->assertDontSee($global->subject);
    }
}
