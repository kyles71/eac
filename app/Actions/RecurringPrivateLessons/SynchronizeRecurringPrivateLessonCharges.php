<?php

declare(strict_types=1);

namespace App\Actions\RecurringPrivateLessons;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Models\Event;
use App\Models\Product;
use App\Models\RecurringPrivateLesson;
use App\Models\RecurringPrivateLessonBillingPeriod;
use App\Models\RecurringPrivateLessonCharge;
use Illuminate\Support\Facades\DB;

final class SynchronizeRecurringPrivateLessonCharges
{
    public function handle(RecurringPrivateLesson $recurringPrivateLesson): int
    {
        return DB::transaction(function () use ($recurringPrivateLesson): int {
            $recurringPrivateLesson->loadMissing(['course.events', 'student']);
            $created = 0;

            /** @var Event $event */
            foreach ($recurringPrivateLesson->course->events->sortBy('start_time') as $event) {
                if ($event->start_time === null) {
                    continue;
                }

                $periodStart = $event->start_time
                    ->timezone((string) config('app.display_timezone', 'America/New_York'))
                    ->startOfMonth()
                    ->toDateString();
                $billingPeriod = RecurringPrivateLessonBillingPeriod::query()
                    ->where('recurring_private_lesson_id', $recurringPrivateLesson->id)
                    ->whereDate('period_start', $periodStart)
                    ->first();

                if (! $billingPeriod instanceof RecurringPrivateLessonBillingPeriod) {
                    $billingPeriod = RecurringPrivateLessonBillingPeriod::query()->create([
                        'recurring_private_lesson_id' => $recurringPrivateLesson->id,
                        'period_start' => $periodStart,
                    ]);
                }
                $existingCharge = RecurringPrivateLessonCharge::query()
                    ->where('event_id', $event->id)
                    ->first();

                if ($existingCharge instanceof RecurringPrivateLessonCharge) {
                    if (in_array($existingCharge->status, [
                        RecurringPrivateLessonChargeStatus::Scheduled,
                        RecurringPrivateLessonChargeStatus::Billed,
                        RecurringPrivateLessonChargeStatus::Paid,
                    ], true)) {
                        $existingCharge->update([
                            'recurring_private_lesson_billing_period_id' => $billingPeriod->id,
                        ]);
                    }

                    if ($existingCharge->status === RecurringPrivateLessonChargeStatus::Scheduled) {
                        $existingCharge->update([
                            'amount' => $recurringPrivateLesson->lesson_price,
                        ]);
                    }

                    if (in_array($existingCharge->status, [
                        RecurringPrivateLessonChargeStatus::Scheduled,
                        RecurringPrivateLessonChargeStatus::Billed,
                        RecurringPrivateLessonChargeStatus::Paid,
                    ], true)) {
                        $existingCharge->product()->update([
                            'name' => $recurringPrivateLesson->student->displayName().' — '.$event->start_time
                                ->timezone((string) config('app.display_timezone', 'America/New_York'))
                                ->format('M j, Y g:i A'),
                            'price' => $existingCharge->amount,
                            'available_until' => $event->start_time->copy()->subDay(),
                        ]);
                    }

                    continue;
                }

                $charge = RecurringPrivateLessonCharge::query()->create([
                    'recurring_private_lesson_id' => $recurringPrivateLesson->id,
                    'recurring_private_lesson_billing_period_id' => $billingPeriod->id,
                    'event_id' => $event->id,
                    'status' => RecurringPrivateLessonChargeStatus::Scheduled,
                    'amount' => $recurringPrivateLesson->lesson_price,
                ]);
                $product = new Product([
                    'name' => $recurringPrivateLesson->student->displayName().' — '.$event->start_time
                        ->timezone((string) config('app.display_timezone', 'America/New_York'))
                        ->format('M j, Y g:i A'),
                    'description' => $recurringPrivateLesson->course->name.' recurring private lesson',
                    'price' => $charge->amount,
                    'is_active' => false,
                    'is_store_listed' => false,
                    'allows_payment_plan' => false,
                    'include_productable_images' => false,
                    'send_purchase_notification' => false,
                    'available_until' => $event->start_time->copy()->subDay(),
                ]);
                $charge->product()->save($product);
                $product->assignedUsers()->attach($recurringPrivateLesson->user_id);
                $created++;
            }

            return $created;
        });
    }
}
