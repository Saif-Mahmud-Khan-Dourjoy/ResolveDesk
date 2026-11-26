<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates tickets for tests. The ticket number is generated after insert,
 * exactly like TicketService does.
 *
 * Example: Ticket::factory()->assignedTo($agent)->create(['created_by' => $customer->id]);
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'category_id' => Category::factory(),
            'created_by' => User::factory()->customer(),
            'assigned_to' => null,
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
            'due_at' => now()->addHours(Ticket::SLA_HOURS[Ticket::PRIORITY_MEDIUM]),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Ticket $ticket) {
            $ticket->ticket_number = Ticket::makeTicketNumber($ticket->id);
            $ticket->save();
        });
    }

    /** Assigned to the given agent with status "assigned". */
    public function assignedTo(User $agent): static
    {
        return $this->state([
            'assigned_to' => $agent->id,
            'status' => Ticket::STATUS_ASSIGNED,
        ]);
    }

    public function inProgress(User $agent): static
    {
        return $this->state([
            'assigned_to' => $agent->id,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);
    }

    public function resolved(User $agent): static
    {
        return $this->state([
            'assigned_to' => $agent->id,
            'status' => Ticket::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolution_note' => 'Fixed the configuration.',
        ]);
    }

    public function closed(User $agent): static
    {
        return $this->state([
            'assigned_to' => $agent->id,
            'status' => Ticket::STATUS_CLOSED,
            'resolved_at' => now(),
            'resolution_note' => 'Fixed the configuration.',
        ]);
    }
}
