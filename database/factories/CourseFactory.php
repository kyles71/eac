<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CourseSemester;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Course>
 */
final class CourseFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Course $course): void {
            $course->teachers()->syncWithoutDetaching([User::factory()->create()->id]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_term_id' => AcademicTerm::factory(),
            'name' => fake()->randomElement(['Tap', 'Acro', 'Ballet', 'Jazz']).' '.fake()->randomElement([1, 2, 3, 4]),
            'description' => fake()->text(),
            'capacity' => fake()->randomElement([10, 15]),
            'is_private' => false,
            'guest_teacher' => null,
        ];
    }

    public function forSemester(CourseSemester $semester, ?int $year = null): static
    {
        return $this->state(function () use ($semester, $year): array {
            $year ??= now()->year;
            $academicTerm = AcademicTerm::query()
                ->where('semester', $semester)
                ->where('year', $year)
                ->first()
                ?? AcademicTerm::factory()->forSemester($semester, $year)->create();

            return ['academic_term_id' => $academicTerm->id];
        });
    }
}
