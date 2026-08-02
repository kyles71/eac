<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CourseHold;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseHold>
 */
final class CourseHoldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'created_by_user_id' => null,
            'expires_at' => now()->addDays(2),
            'notes' => fake()->optional()->sentence(),
            'reminder_sent_at' => null,
        ];
    }
}
