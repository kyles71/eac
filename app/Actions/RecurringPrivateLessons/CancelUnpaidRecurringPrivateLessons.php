<?php

declare(strict_types=1);

namespace App\Actions\RecurringPrivateLessons;

use App\Actions\Mail\SendRecurringPrivateLessonEmail;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Models\RecurringPrivateLessonCharge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CancelUnpaidRecurringPrivateLessons
{
    public function __construct(private readonly SendRecurringPrivateLessonEmail $emails) {}

    public function handle(): int
    {
        $cancelled = 0;

        RecurringPrivateLessonCharge::query()
            ->whereIn('status', [
                RecurringPrivateLessonChargeStatus::Scheduled,
                RecurringPrivateLessonChargeStatus::Billed,
            ])
            ->whereHas('event', fn (Builder $query): Builder => $query
                ->whereNull('cancelled_at')
                ->whereNotNull('start_time')
                ->where('start_time', '<=', now()->addDay()))
            ->with('event')
            ->orderBy('id')
            ->eachById(function (RecurringPrivateLessonCharge $charge) use (&$cancelled): void {
                DB::transaction(function () use ($charge, &$cancelled): void {
                    $lockedCharge = RecurringPrivateLessonCharge::query()
                        ->with('event')
                        ->lockForUpdate()
                        ->find($charge->id);

                    if (! $lockedCharge instanceof RecurringPrivateLessonCharge
                        || ! in_array($lockedCharge->status, [
                            RecurringPrivateLessonChargeStatus::Scheduled,
                            RecurringPrivateLessonChargeStatus::Billed,
                        ], true)
                        || $lockedCharge->event->isCancelled()
                        || $lockedCharge->event->start_time === null
                        || $lockedCharge->event->start_time->gt(now()->addDay())) {
                        return;
                    }

                    $lockedCharge->update(['automatically_cancelled_at' => now()]);
                    $lockedCharge->event->update([
                        'cancellation_reason' => 'Payment was not completed more than 24 hours before the lesson.',
                        'cancelled_at' => now(),
                        'cancelled_by_user_id' => null,
                    ]);
                    $cancelled++;
                });

                $charge->refresh();

                if ($charge->automatically_cancelled_at !== null) {
                    $this->emails->automaticCancellation($charge);
                }
            });

        return $cancelled;
    }
}
