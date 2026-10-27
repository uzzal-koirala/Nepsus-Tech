<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /** The two departments people can be in for now (Employee::CHOOSABLE_DEPARTMENTS). */
    private const ROLES = [
        'content-creator' => ['Content Writer', 'Camera Person', 'Social Media Manager'],
        'video-editor' => ['Video Editor', 'Motion Designer'],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $department = fake()->randomElement(array_keys(self::ROLES));

        return [
            'name' => fake()->name(),
            'designation' => fake()->randomElement(self::ROLES[$department]),
            'department' => $department,
            'email' => fake()->unique()->safeEmail(),
            'phone' => '98'.fake()->numerify('########'),
            'status' => fake()->randomElement(['active', 'active', 'active', 'active', 'on_leave', 'inactive']),
            'salary' => fake()->randomElement([25000, 32000, 40000, 48000, 55000, 65000]),
            'joined_on' => fake()->dateTimeBetween('-4 years', '-1 week'),
        ];
    }
}
