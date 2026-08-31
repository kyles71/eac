<?php

declare(strict_types=1);

namespace App\Actions\RecurringPrivateLessons;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonStatus;
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
            $lockedLesson = RecurringPrivateLesson::query()
                ->lockForUpdate()
                ->findOrFail($recurringPrivateLesson->id);

            if ($lockedLesson->status !== RecurringPrivateLessonStatus::Active) {
                return 0;
            }

            $lockedLesson->loadMissing(['course.events', 'student']);
            $created = 0;

            /** @var Event $event */
            foreach ($lockedLesson->course->events->sortBy('start_time') as $event) {
                if ($event->start_time === null) {
                    continue;
                }

                $periodStart = $event->start_time
                    ->timezone((string) config('app.display_timezone', 'America/New_York'))
                    ->startOfMonth()
                    ->toDateString();
                $billingPeriod = RecurringPrivateLessonBillingPeriod::query()
                    ->where('recurring_private_lesson_id', $lockedLesson->id)
                    ->whereDate('period_start', $periodStart)
                    ->first();

                if (! $billingPeriod instanceof RecurringPrivateLessonBillingPeriod) {
                    $billingPeriod = RecurringPrivateLessonBillingPeriod::query()->create([
                        'recurring_private_lesson_id' => $lockedLesson->id,
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
                            'amount' => $lockedLesson->lesson_price,
                        ]);
                    }

                    if (in_array($existingCharge->status, [
                        RecurringPrivateLessonChargeStatus::Scheduled,
                        RecurringPrivateLessonChargeStatus::Billed,
                        RecurringPrivateLessonChargeStatus::Paid,
                    ], true)) {
                        $existingCharge->product()->update([
                            'name' => $lockedLesson->student->displayName().' — '.$event->start_time
                                ->timezone((string) config('app.display_timezone', 'America/New_York'))
                                ->format('M j, Y g:i A'),
                            'price' => $existingCharge->amount,
                            'is_active' => $existingCharge->status === RecurringPrivateLessonChargeStatus::Billed
                                && ! $event->isCancelled()
                                && $event->start_time->gt(now()->addDay()),
                            'available_until' => $event->start_time->copy()->subDay(),
                        ]);
                    }

                    continue;
                }

                $charge = RecurringPrivateLessonCharge::query()->create([
                    'recurring_private_lesson_id' => $lockedLesson->id,
                    'recurring_private_lesson_billing_period_id' => $billingPeriod->id,
                    'event_id' => $event->id,
                    'status' => RecurringPrivateLessonChargeStatus::Scheduled,
                    'amount' => $lockedLesson->lesson_price,
                ]);
                $product = new Product([
                    'name' => $lockedLesson->student->displayName().' — '.$event->start_time
                        ->timezone((string) config('app.display_timezone', 'America/New_York'))
                        ->format('M j, Y g:i A'),
                    'description' => $lockedLesson->course->name.' recurring private lesson',
                    'price' => $charge->amount,
                    'is_active' => false,
                    'is_store_listed' => false,
                    'allows_payment_plan' => false,
                    'include_productable_images' => false,
                    'send_purchase_notification' => false,
                    'available_until' => $event->start_time->copy()->subDay(),
                ]);
                $charge->product()->save($product);
                $product->assignedUsers()->attach($lockedLesson->user_id);
                $created++;
            }

            return $created;
        });
    }
}
