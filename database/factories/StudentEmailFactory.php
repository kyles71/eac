<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentEmail>
 */
final class StudentEmailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'email' => fake()->unique()->safeEmail(),
            'relationship' => fake()->randomElement(['Mother', 'Father', 'Dancer', 'Guardian']),
        ];
    }
}
