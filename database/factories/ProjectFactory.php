<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test data for projects. Example: Project::factory()->create(['code' => 'HRMS']);
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(3, true)) . ' System',
            'code' => strtoupper(fake()->unique()->bothify('PRJ-###??')),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
