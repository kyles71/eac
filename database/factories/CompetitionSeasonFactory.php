<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompetitionSeason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetitionSeason>
 */
final class CompetitionSeasonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2100, 2999);
        $startsOn = now()->setYear($year)->startOfYear();

        return [
            'name' => "Competition Season {$year}",
            'starts_on' => $startsOn,
            'ends_on' => $startsOn->copy()->endOfYear(),
        ];
    }

    public function current(): static
    {
        return $this->state(fn (): array => [
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonths(9)->toDateString(),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => [
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => now()->subMonths(2)->toDateString(),
        ]);
    }
}
