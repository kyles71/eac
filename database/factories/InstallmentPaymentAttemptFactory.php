<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Models\InstallmentPaymentAttempt;
use App\Models\PaymentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstallmentPaymentAttempt>
 */
final class InstallmentPaymentAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_plan_id' => PaymentPlan::factory(),
            'initiated_by_user_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'origin' => InstallmentPaymentAttemptOrigin::Scheduled,
            'status' => InstallmentPaymentAttemptStatus::Pending,
            'total_amount' => fake()->numberBetween(1000, 10000),
            'stripe_customer_id' => 'cus_'.fake()->unique()->lexify('????????'),
            'use_for_future' => false,
        ];
    }
}
