<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderRefundPaymentStatus;
use App\Models\OrderRefund;
use App\Models\OrderRefundPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderRefundPayment>
 */
final class OrderRefundPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_refund_id' => OrderRefund::factory(),
            'stripe_payment_intent_id' => 'pi_test_'.fake()->uuid(),
            'stripe_refund_id' => null,
            'amount' => 1000,
            'status' => OrderRefundPaymentStatus::Processing,
            'failure_reason' => null,
            'refunded_at' => null,
        ];
    }
}
