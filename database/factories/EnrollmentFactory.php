<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
final class EnrollmentFactory extends Factory
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
            'student_id' => null,
        ];
    }

    /**
     * Create an enrollment with a student assigned.
     */
    public function withStudent(?Student $student = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'student_id' => $student?->id ?? Student::factory(),
        ]);
    }
}
