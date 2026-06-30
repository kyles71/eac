<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DashboardAudience;
use App\Enums\ManagedBannerRenderLocation;
use App\Enums\ManagedBannerTone;
use App\Models\ManagedBanner;
use App\Services\ManagedBannerDestinationService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagedBanner>
 */
final class ManagedBannerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'message' => fake()->sentence(),
            'is_active' => true,
            'published_at' => now(),
            'expires_at' => null,
            'render_location' => ManagedBannerRenderLocation::ContentStart,
            'target_scopes' => [],
            'audiences' => [DashboardAudience::Eac->value],
            'tone' => ManagedBannerTone::Info,
            'icon' => null,
            'cta_label' => null,
            'cta_destination' => ManagedBannerDestinationService::EXTERNAL,
            'cta_url' => null,
            'cta_new_tab' => false,
            'is_dismissible' => false,
        ];
    }

    public function dismissible(): static
    {
        return $this->state(fn (): array => [
            'is_dismissible' => true,
        ]);
    }

    public function forScope(string $scope): static
    {
        return $this->state(fn (): array => [
            'target_scopes' => [$scope],
        ]);
    }

    public function forRenderLocation(ManagedBannerRenderLocation $renderLocation): static
    {
        return $this->state(fn (): array => [
            'render_location' => $renderLocation,
        ]);
    }
}
