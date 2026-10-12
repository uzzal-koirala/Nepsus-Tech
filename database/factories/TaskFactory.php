<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement([
                'Design Facebook cover', 'Write 10 Instagram captions', 'Schedule weekly posts', 'Edit TikTok reel',
                'Monthly performance report', 'Update Google Business photos', 'Reply to reviews', 'Run boosted post',
                'Product photoshoot', 'Website speed check', 'Keyword research', 'Content calendar for next month',
            ]),
            'type' => fake()->randomElement(array_keys(Task::TYPES)),
            'due_date' => fake()->optional(0.8)->dateTimeBetween('-3 weeks', '+3 weeks'),
            'completed_at' => fake()->optional(0.55)->dateTimeBetween('-1 month'),
        ];
    }
}
