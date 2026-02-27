<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\DiscountCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DiscountCode>
 */
final class DiscountCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::upper(fake()->unique()->bothify('???###')),
            'type' => DiscountType::Percentage,
            'value' => fake()->randomElement([10, 15, 20, 25]),
            'min_order_amount' => null,
            'max_uses' => null,
            'times_used' => 0,
            'max_uses_per_user' => null,
            'expires_at' => null,
            'is_active' => true,
        ];
    }

    /**
     * Create a fixed-amount discount code.
     */
    public function fixedAmount(int $amountInCents = 1000): static
    {
        return $this->state([
            'type' => DiscountType::FixedAmount,
            'value' => $amountInCents,
        ]);
    }

    /**
     * Create a percentage discount code.
     */
    public function percentage(int $percent = 20): static
    {
        return $this->state([
            'type' => DiscountType::Percentage,
            'value' => $percent,
        ]);
    }

    /**
     * Create an expired discount code.
     */
    public function expired(): static
    {
        return $this->state([
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Create an inactive discount code.
     */
    public function inactive(): static
    {
        return $this->state([
            'is_active' => false,
        ]);
    }

    /**
     * Create a discount code with a max usage limit.
     */
    public function maxUses(int $limit = 10): static
    {
        return $this->state([
            'max_uses' => $limit,
        ]);
    }

    /**
     * Create a discount code that has been fully used.
     */
    public function exhausted(): static
    {
        return $this->state([
            'max_uses' => 5,
            'times_used' => 5,
        ]);
    }

    /**
     * Create a discount code limited per user.
     */
    public function perUser(int $limit = 1): static
    {
        return $this->state([
            'max_uses_per_user' => $limit,
        ]);
    }

    /**
     * Create a discount code with a minimum order amount.
     */
    public function minOrderAmount(int $amountInCents): static
    {
        return $this->state([
            'min_order_amount' => $amountInCents,
        ]);
    }
}
