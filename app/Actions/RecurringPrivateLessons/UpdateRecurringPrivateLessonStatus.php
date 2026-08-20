<?php

declare(strict_types=1);

namespace App\Actions\RecurringPrivateLessons;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Enums\RecurringPrivateLessonStatus;
use App\Models\RecurringPrivateLesson;
use App\Models\RecurringPrivateLessonCharge;
use Illuminate\Support\Facades\DB;

final readonly class UpdateRecurringPrivateLessonStatus
{
    public function __construct(private SynchronizeRecurringPrivateLessonCharges $synchronizeCharges) {}

    public function handle(
        RecurringPrivateLesson $recurringPrivateLesson,
        RecurringPrivateLessonStatus $status,
    ): RecurringPrivateLesson {
        $recurringPrivateLesson = DB::transaction(function () use ($recurringPrivateLesson, $status): RecurringPrivateLesson {
            $lockedLesson = RecurringPrivateLesson::query()
                ->lockForUpdate()
                ->findOrFail($recurringPrivateLesson->id);

            $lockedLesson->update(['status' => $status]);

            if ($status !== RecurringPrivateLessonStatus::Active) {
                $lockedLesson->charges()
                    ->whereIn('status', [
                        RecurringPrivateLessonChargeStatus::Scheduled,
                        RecurringPrivateLessonChargeStatus::Billed,
                    ])
                    ->with('product')
                    ->each(function (RecurringPrivateLessonCharge $charge): void {
                        $charge->product?->update(['is_active' => false]);
                        $charge->product?->cartItems()->delete();
                    });
            }

            return $lockedLesson;
        });

        if ($status === RecurringPrivateLessonStatus::Active) {
            $this->synchronizeCharges->handle($recurringPrivateLesson);
        }

        return $recurringPrivateLesson->refresh();
    }
}
