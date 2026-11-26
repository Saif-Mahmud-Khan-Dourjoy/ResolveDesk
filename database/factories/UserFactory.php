<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates fake users for tests: User::factory()->agent()->create();
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** The same hashed password is reused so tests stay fast. */
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => User::ROLE_ADMIN]);
    }

    public function agent(): static
    {
        return $this->state(['role' => User::ROLE_AGENT]);
    }

    public function customer(): static
    {
        return $this->state(['role' => User::ROLE_CUSTOMER]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
