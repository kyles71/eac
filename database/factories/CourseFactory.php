<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CourseSemester;
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
            'name' => fake()->randomElement(['Tap', 'Acro', 'Ballet', 'Jazz']).' '.fake()->randomElement([1, 2, 3, 4]),
            'description' => fake()->text(),
            'semester' => fake()->randomElement(CourseSemester::cases())->value,
            'capacity' => fake()->randomElement([10, 15]),
            'guest_teacher' => null,
        ];
    }
}
