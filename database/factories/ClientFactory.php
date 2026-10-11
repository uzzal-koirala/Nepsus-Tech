<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /** Sample prices per package tier. */
    private const PRICES = [
        'basic' => [8000, 10000, 12500],
        'standard' => [18000, 20000, 25000],
        'premium' => [35000, 40000, 45000],
        'enterprise' => [60000, 75000, 90000],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $handle = Str::slug(fake()->unique()->userName(), '');

        return [
            'name' => fake()->name(),
            'company' => fake()->optional(0.85)->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional(0.9)->phoneNumber(),
            'status' => fake()->randomElement(['active', 'active', 'active', 'lead', 'inactive']),
            'notes' => fake()->optional(0.4)->sentence(),
            'package' => $package = fake()->optional(0.9)->randomElement(array_keys(Client::PACKAGES)),
            'price' => $package ? fake()->randomElement(self::PRICES[$package]) : null,
            'billing_cycle' => fake()->randomElement(Client::BILLING_CYCLES),
            'progress' => fake()->randomElement([0, 10, 25, 40, 55, 70, 85, 100]),
            'facebook_url' => fake()->optional(0.8)->passthrough("https://facebook.com/{$handle}"),
            'instagram_url' => fake()->optional(0.7)->passthrough("https://instagram.com/{$handle}"),
            'tiktok_url' => fake()->optional(0.4)->passthrough("https://tiktok.com/@{$handle}"),
            'google_business_url' => fake()->optional(0.5)->passthrough('https://g.page/'.$handle),
        ];
    }
}
