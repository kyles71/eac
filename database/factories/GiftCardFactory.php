<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GiftCard>
 */
final class GiftCardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomElement([2500, 5000, 10000]);

        return [
            'code' => mb_strtoupper(Str::random(12)),
            'initial_amount' => $amount,
            'remaining_amount' => $amount,
            'purchased_by_user_id' => User::factory(),
            'redeemed_by_user_id' => null,
            'order_id' => null,
            'is_active' => true,
            'redeemed_at' => null,
        ];
    }

    /**
     * A specific amount in cents.
     */
    public function amount(int $cents): static
    {
        return $this->state(fn (array $attributes): array => [
            'initial_amount' => $cents,
            'remaining_amount' => $cents,
        ]);
    }

    /**
     * Mark the gift card as redeemed by a given user.
     */
    public function redeemed(?User $user = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'redeemed_by_user_id' => $user?->id ?? User::factory(),
            'redeemed_at' => now(),
            'remaining_amount' => 0,
        ]);
    }

    /**
     * Mark the gift card as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
