<?php

namespace Database\Factories;

use App\Models\Package;
use App\Support\PackageTheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Starter', 'Growth', 'Pro', 'Elite', 'Launch', 'Scale', 'Signature']),
            'description' => fake()->sentence(5),
            'icon' => fake()->randomElement(PackageTheme::ICONS),
            'color' => fake()->randomElement(array_keys(PackageTheme::THEMES)),
            'price' => fake()->randomElement([10000, 20000, 40000, 75000]),
            'billing_cycle' => 'monthly',
            'features' => ['Social media posts', 'Monthly report'],
            'is_active' => true,
            'position' => 0,
        ];
    }
}
