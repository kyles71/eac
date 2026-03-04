<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShowcaseParticipation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShowcaseParticipation> */
final class ShowcaseParticipationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'is_participating' => fake()->boolean(),
        ];
    }
}
