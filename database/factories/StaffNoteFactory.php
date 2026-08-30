<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StaffNote;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffNote>
 */
final class StaffNoteFactory extends Factory
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
            'author_id' => User::factory(),
            'note' => fake()->paragraph(),
        ];
    }
}
