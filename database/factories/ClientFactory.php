<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company' => fake()->optional(0.85)->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional(0.9)->phoneNumber(),
            'status' => fake()->randomElement(['active', 'active', 'active', 'lead', 'inactive']),
            'notes' => fake()->optional(0.4)->sentence(),
        ];
    }
}
