<?php

declare(strict_types=1);

namespace App\Actions\RecurringPrivateLessons;

use App\Actions\Mail\SendRecurringPrivateLessonEmail;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Models\RecurringPrivateLessonBillingPeriod;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class BillRecurringPrivateLessonBillingPeriod
{
    public function __construct(private SendRecurringPrivateLessonEmail $emails) {}

    public function handle(RecurringPrivateLessonBillingPeriod $billingPeriod, User $billedBy): int
    {
        $billedCount = DB::transaction(function () use ($billingPeriod, $billedBy): int {
            $billingPeriod->loadMissing('charges.event');
            $billedCount = 0;

            /** @var RecurringPrivateLessonCharge $charge */
            foreach ($billingPeriod->charges as $charge) {
                if ($charge->status !== RecurringPrivateLessonChargeStatus::Scheduled
                    || $charge->event->isCancelled()
                    || $charge->event->start_time === null
                    || ! $charge->event->start_time->gt(now()->addDay())) {
                    continue;
                }

                $charge->update([
                    'status' => RecurringPrivateLessonChargeStatus::Billed,
                    'billed_at' => now(),
                    'billed_by_user_id' => $billedBy->id,
                ]);
                $charge->product()->update([
                    'price' => $charge->amount,
                    'is_active' => true,
                ]);
                $billedCount++;
            }

            if ($billedCount === 0) {
                throw new InvalidArgumentException('There are no eligible scheduled lessons to bill for this month.');
            }

            $billingPeriod->update([
                'last_billed_at' => now(),
                'last_billed_by_user_id' => $billedBy->id,
            ]);

            return $billedCount;
        });

        $this->emails->billingPeriod($billingPeriod->refresh());

        return $billedCount;
    }
}
