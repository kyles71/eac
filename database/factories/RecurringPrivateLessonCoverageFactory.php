<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RecurringPrivateLessonCoverageStatus;
use App\Models\OrderItem;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\RecurringPrivateLessonCoverage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringPrivateLessonCoverage>
 */
final class RecurringPrivateLessonCoverageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $charge = RecurringPrivateLessonCharge::factory();
        $grossAmount = fake()->randomElement([5000, 6000, 7500]);

        return [
            'recurring_private_lesson_charge_id' => $charge,
            'order_item_id' => OrderItem::factory(),
            'status' => RecurringPrivateLessonCoverageStatus::Active,
            'gross_amount' => $grossAmount,
            'discount_amount' => 0,
            'restricted_credit_amount' => 0,
            'credit_amount' => 0,
            'stripe_amount' => $grossAmount,
        ];
    }
}
