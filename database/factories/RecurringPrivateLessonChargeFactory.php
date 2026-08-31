<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Models\Event;
use App\Models\RecurringPrivateLesson;
use App\Models\RecurringPrivateLessonBillingPeriod;
use App\Models\RecurringPrivateLessonCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringPrivateLessonCharge>
 */
final class RecurringPrivateLessonChargeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recurringPrivateLesson = RecurringPrivateLesson::factory();

        return [
            'recurring_private_lesson_id' => $recurringPrivateLesson,
            'recurring_private_lesson_billing_period_id' => RecurringPrivateLessonBillingPeriod::factory()
                ->for($recurringPrivateLesson),
            'event_id' => Event::factory(),
            'status' => RecurringPrivateLessonChargeStatus::Scheduled,
            'amount' => fake()->randomElement([5000, 6000, 7500]),
        ];
    }
}
