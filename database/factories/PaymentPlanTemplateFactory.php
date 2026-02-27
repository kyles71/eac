<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentPlanFrequency;
use App\Enums\ProductType;
use App\Models\PaymentPlanTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlanTemplate>
 */
final class PaymentPlanTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' Plan',
            'product_type' => ProductType::Any,
            'min_price' => 5000,
            'max_price' => 50000,
            'number_of_installments' => 3,
            'frequency' => PaymentPlanFrequency::Monthly,
            'is_active' => true,
        ];
    }

    /**
     * Mark the template as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Set the template for a specific product type.
     */
    public function forProductType(ProductType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_type' => $type,
        ]);
    }

    /**
     * Set the frequency.
     */
    public function frequency(PaymentPlanFrequency $frequency): static
    {
        return $this->state(fn (array $attributes): array => [
            'frequency' => $frequency,
        ]);
    }
}
