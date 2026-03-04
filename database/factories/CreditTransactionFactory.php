<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditTransaction> */
final class CreditTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => fake()->randomElement([1000, 2500, 5000, 10000]),
            'type' => fake()->randomElement(CreditTransactionType::cases()),
            'reference_type' => null,
            'reference_id' => null,
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function giftCardRedemption(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CreditTransactionType::GiftCardRedemption,
        ]);
    }

    public function checkoutDebit(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CreditTransactionType::CheckoutDebit,
            'amount' => -1 * abs(fake()->randomElement([1000, 2500, 5000])),
        ]);
    }

    public function refund(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CreditTransactionType::Refund,
        ]);
    }

    public function adminAdjustment(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CreditTransactionType::AdminAdjustment,
            'description' => 'Admin adjustment: '.fake()->sentence(),
        ]);
    }
}
