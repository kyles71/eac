<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DashboardAudience;
use App\Models\DashboardMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardMessage>
 */
final class DashboardMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message' => fake()->sentence(),
            'audience' => DashboardAudience::Eac,
            'published_at' => now(),
            'expires_at' => null,
        ];
    }
}
