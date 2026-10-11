<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $due = fake()->dateTimeBetween('-4 months', '+1 month');

        return [
            'amount' => fake()->randomElement([8000, 12500, 18000, 25000, 35000]),
            'due_date' => $due,
            'paid_at' => $due < now() && fake()->boolean(75) ? $due : null,
            'method' => fake()->randomElement(Payment::METHODS),
            'reference' => strtoupper(fake()->bothify('INV-####')),
        ];
    }
}
