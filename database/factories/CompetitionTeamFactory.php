<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetitionTeam>
 */
final class CompetitionTeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_season_id' => CompetitionSeason::factory(),
            'name' => fake()->unique()->randomElement(['Mini', 'Junior', 'Teen', 'Senior', 'Elite']).' '.fake()->unique()->numerify('##'),
        ];
    }
}
