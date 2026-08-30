<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\InstallmentDueDateAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstallmentDueDateAdjustment>
 */
final class InstallmentDueDateAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'adjustment_batch_uuid' => (string) Str::uuid(),
            'installment_id' => Installment::factory(),
            'adjusted_by_user_id' => User::factory(),
            'old_due_date' => now()->addWeek(),
            'new_due_date' => now()->addWeeks(2),
            'previous_status' => InstallmentStatus::Pending,
            'previous_retry_count' => 0,
            'reason' => fake()->sentence(),
            'customer_notification_status' => 'Queued',
            'customer_notification_note' => null,
        ];
    }
}
