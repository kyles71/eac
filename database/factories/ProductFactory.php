<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DashboardAudience;
use App\Enums\FulfillmentWorkflow;
use App\Models\Course;
use App\Models\Gear;
use App\Models\GiftCardType;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomElement([2500, 5000, 7500, 10000, 12500, 15000]),
            'is_active' => true,
            'include_productable_images' => false,
            'send_purchase_notification' => false,
            'fulfillment_workflow' => FulfillmentWorkflow::Manual,
            'available_from' => null,
            'available_until' => null,
            'productable_type' => null,
            'productable_id' => null,
        ];
    }

    /**
     * Create a product linked to a Course.
     */
    public function forCourse(?Course $course = null): static
    {
        return $this->state(function (array $attributes) use ($course): array {
            $course ??= Course::factory()->create();

            return [
                'name' => $course->name,
                'productable_type' => Course::class,
                'productable_id' => $course->id,
                'fulfillment_workflow' => FulfillmentWorkflow::Automatic,
            ];
        });
    }

    /**
     * Create a product linked to a GiftCardType.
     */
    public function forGiftCardType(?GiftCardType $giftCardType = null): static
    {
        return $this->state(function (array $attributes) use ($giftCardType): array {
            $giftCardType ??= GiftCardType::factory()->create();

            return [
                'name' => $giftCardType->name,
                'productable_type' => GiftCardType::class,
                'productable_id' => $giftCardType->id,
                'price' => $giftCardType->allows_custom_amount ? null : $giftCardType->denomination,
                'fulfillment_workflow' => FulfillmentWorkflow::Automatic,
            ];
        });
    }

    /**
     * Mark the product as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function availableFrom(CarbonInterface|string $availableFrom): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_from' => $availableFrom,
        ]);
    }

    public function availableUntil(CarbonInterface|string $availableUntil): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_until' => $availableUntil,
        ]);
    }

    public function withEarlyAccessAudience(DashboardAudience $audience): static
    {
        return $this->afterCreating(function (Product $product) use ($audience): void {
            ProductEarlyAccessWindow::factory()
                ->for($product)
                ->create([
                    'audiences' => [$audience->value],
                ]);
        });
    }

    /**
     * Create a product linked to Gear.
     */
    public function forGear(?Gear $gear = null): static
    {
        return $this->state(function (array $attributes) use ($gear): array {
            $gear ??= Gear::factory()->create();

            return [
                'name' => $gear->name,
                'productable_type' => Gear::class,
                'productable_id' => $gear->id,
            ];
        });
    }

    /**
     * Create a standalone product (no productable morph).
     */
    public function standalone(): static
    {
        return $this->state(fn (array $attributes): array => [
            'productable_type' => null,
            'productable_id' => null,
        ]);
    }
}
