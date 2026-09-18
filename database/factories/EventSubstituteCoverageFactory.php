<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventSubstituteCoverage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSubstituteCoverage>
 */
final class EventSubstituteCoverageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'covered_teacher_id' => User::factory()->isTeacher(),
            'needed_at' => now(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'substitute_teacher_id' => User::factory()->isTeacher(),
        ]);
    }
}
