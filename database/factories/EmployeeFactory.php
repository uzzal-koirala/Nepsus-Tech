<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    private const ROLES = [
        'design' => ['Graphic Designer', 'UI/UX Designer', 'Motion Designer'],
        'development' => ['Laravel Developer', 'Frontend Developer', 'WordPress Developer'],
        'marketing' => ['Social Media Manager', 'SEO Specialist', 'Ads Specialist'],
        'content' => ['Content Writer', 'Video Editor', 'Photographer'],
        'sales' => ['Account Manager', 'Sales Executive'],
        'management' => ['Project Manager', 'Operations Lead'],
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
            'joined_on' => fake()->dateTimeBetween('-4 years', '-1 week'),
        ];
    }
}
