<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'other',
            'name' => fake()->word().'.pdf',
            'path' => 'clients/'.fake()->uuid().'.pdf',
            'size' => fake()->numberBetween(20_000, 2_000_000),
        ];
    }
}
