<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GiftCardType>
 */
final class GiftCardTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => '$'.fake()->randomElement([25, 50, 100]).' Gift Card',
            'denomination' => fake()->randomElement([2500, 5000, 10000]),
            'restricted_to_product_type' => null,
        ];
    }

    /**
     * A custom-amount gift card type (denomination = 0).
     */
    public function custom(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Custom Gift Card',
            'denomination' => 0,
        ]);
    }

    /**
     * A gift card type with a specific denomination in cents.
     */
    public function denomination(int $cents): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => '$'.number_format($cents / 100, 2).' Gift Card',
            'denomination' => $cents,
        ]);
    }

    /**
     * Restrict this gift card type to a specific product type.
     */
    public function restrictedToProductType(ProductType $productType): static
    {
        return $this->state(fn (array $attributes): array => [
            'restricted_to_product_type' => $productType,
        ]);
    }
}
