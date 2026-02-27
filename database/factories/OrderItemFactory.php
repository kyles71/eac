<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderItemStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
final class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->randomElement([2500, 5000, 7500, 10000]);
        $quantity = fake()->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
            'status' => OrderItemStatus::Pending,
        ];
    }

    /**
     * Mark the order item as fulfilled.
     */
    public function fulfilled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderItemStatus::Fulfilled,
        ]);
    }
}
