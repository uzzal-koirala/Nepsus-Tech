<?php

namespace Database\Factories;

use App\Models\SalaryPayment;
use App\Support\NepaliDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryPayment>
 */
class SalaryPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period' => NepaliDate::period(today()),
            'amount' => fake()->randomElement([10000, 15000, 20000, 30000]),
            'paid_on' => today(),
        ];
    }
}
