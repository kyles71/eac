<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\CreditGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditGrant> */
final class CreditGrantFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomElement([1000, 2500, 5000, 10000]);

        return [
            'user_id' => User::factory(),
            'granted_by_user_id' => null,
            'source_type' => null,
            'source_id' => null,
            'initial_amount' => $amount,
            'remaining_amount' => $amount,
            'description' => fake()->sentence(4),
            'restricted_to_product_type' => null,
            'has_product_restrictions' => false,
            'expires_on' => null,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revocation_reason' => null,
        ];
    }

    public function amount(int $amount): static
    {
        return $this->state(fn (): array => [
            'initial_amount' => $amount,
            'remaining_amount' => $amount,
        ]);
    }

    public function restrictedTo(ProductType $productType): static
    {
        return $this->state(fn (): array => [
            'restricted_to_product_type' => $productType,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_on' => now('America/New_York')->subDay()->toDateString(),
        ]);
    }
}
