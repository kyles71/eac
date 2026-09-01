<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RecurringPrivateLessonStatus;
use App\Models\Course;
use App\Models\RecurringPrivateLesson;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringPrivateLesson>
 */
final class RecurringPrivateLessonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'user_id' => User::factory(),
            'student_id' => Student::factory(),
            'lesson_price' => fake()->randomElement([5000, 6000, 7500]),
            'status' => RecurringPrivateLessonStatus::Active,
        ];
    }
}
