<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\PaymentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installment>
 */
final class InstallmentFactory extends Factory
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
            'installment_number' => 1,
            'amount' => 3334,
            'due_date' => now()->addMonth(),
            'status' => InstallmentStatus::Pending,
            'paid_at' => null,
            'stripe_payment_intent_id' => null,
            'stripe_invoice_id' => null,
            'retry_count' => 0,
        ];
    }

    /**
     * Mark as paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InstallmentStatus::Paid,
            'paid_at' => now(),
            'stripe_payment_intent_id' => 'pi_test_'.fake()->uuid(),
        ]);
    }

    /**
     * Mark as failed.
     */
    public function failed(int $retryCount = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InstallmentStatus::Failed,
            'retry_count' => $retryCount,
        ]);
    }

    /**
     * Mark as overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InstallmentStatus::Overdue,
            'retry_count' => 3,
        ]);
    }

    /**
     * Make due today.
     */
    public function dueToday(): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_date' => now()->toDateString(),
        ]);
    }

    /**
     * Make due in the past.
     */
    public function pastDue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_date' => now()->subDays(5)->toDateString(),
        ]);
    }
}
