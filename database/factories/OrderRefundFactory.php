<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderRefundStatus;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderRefund>
 */
final class OrderRefundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->completed(),
            'processed_by_user_id' => User::factory(),
            'amount' => 1000,
            'reason' => fake()->sentence(),
            'cancel_remaining_installments' => false,
            'restore_store_credit' => false,
            'enrollment_ids' => [],
            'enrollment_details' => [],
            'status' => OrderRefundStatus::Processing,
        ];
    }
}
