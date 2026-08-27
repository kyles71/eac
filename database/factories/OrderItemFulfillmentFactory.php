<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\OrderItemFulfillment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItemFulfillment>
 */
final class OrderItemFulfillmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'unit_number' => 1,
            'source_type' => null,
            'source_id' => null,
            'fulfilled_by_user_id' => User::factory(),
            'fulfilled_at' => now(),
            'note' => null,
            'voided_by_user_id' => null,
            'voided_at' => null,
            'void_reason' => null,
        ];
    }
}
