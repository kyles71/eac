<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RecurringPrivateLesson;
use App\Models\RecurringPrivateLessonBillingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringPrivateLessonBillingPeriod>
 */
final class RecurringPrivateLessonBillingPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recurring_private_lesson_id' => RecurringPrivateLesson::factory(),
            'period_start' => now('America/New_York')->startOfMonth()->toDateString(),
            'last_billed_at' => null,
            'last_billed_by_user_id' => null,
        ];
    }
}
