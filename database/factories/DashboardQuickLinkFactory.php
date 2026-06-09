<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DashboardAudience;
use App\Models\DashboardQuickLink;
use App\Services\DashboardQuickLinkDestinationService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardQuickLink>
 */
final class DashboardQuickLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->words(2, true),
            'audience' => DashboardAudience::Eac,
            'destination' => DashboardQuickLinkDestinationService::EXTERNAL,
            'external_url' => fake()->url(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
