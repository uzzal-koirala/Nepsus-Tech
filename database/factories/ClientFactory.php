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
            'package' => fake()->optional(0.85)->randomElement(['Social Media Starter', 'Social Media Growth', 'Premium Marketing', 'SEO Essentials', 'Website Care']),
            'price' => fake()->optional(0.85)->randomElement([8000, 12500, 18000, 25000, 35000, 50000]),
            'billing_cycle' => fake()->randomElement(Client::BILLING_CYCLES),
            'facebook_url' => fake()->optional(0.8)->passthrough("https://facebook.com/{$handle}"),
            'instagram_url' => fake()->optional(0.7)->passthrough("https://instagram.com/{$handle}"),
            'tiktok_url' => fake()->optional(0.4)->passthrough("https://tiktok.com/@{$handle}"),
            'google_business_url' => fake()->optional(0.5)->passthrough('https://g.page/'.$handle),
        ];
    }
}
