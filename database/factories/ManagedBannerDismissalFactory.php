<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ManagedBanner;
use App\Models\ManagedBannerDismissal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagedBannerDismissal>
 */
final class ManagedBannerDismissalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'managed_banner_id' => ManagedBanner::factory(),
            'user_id' => User::factory(),
            'dismissed_at' => now(),
        ];
    }
}
