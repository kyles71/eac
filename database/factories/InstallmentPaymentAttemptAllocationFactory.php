<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Installment;
use App\Models\InstallmentPaymentAttempt;
use App\Models\InstallmentPaymentAttemptAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallmentPaymentAttemptAllocation>
 */
final class InstallmentPaymentAttemptAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'installment_payment_attempt_id' => InstallmentPaymentAttempt::factory(),
            'installment_id' => Installment::factory(),
            'amount' => fake()->numberBetween(1000, 10000),
        ];
    }
}
