<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomElement([2500, 5000, 7500, 10000, 15000]);

        return [
            'user_id' => User::factory(),
            'status' => OrderStatus::Pending,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'stripe_checkout_session_id' => null,
            'stripe_payment_intent_id' => null,
        ];
    }

    /**
     * Mark order as completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Completed,
            'stripe_checkout_session_id' => 'cs_test_'.fake()->uuid(),
            'stripe_payment_intent_id' => 'pi_test_'.fake()->uuid(),
        ]);
    }

    /**
     * Mark order as failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrderStatus::Failed,
        ]);
    }
}
