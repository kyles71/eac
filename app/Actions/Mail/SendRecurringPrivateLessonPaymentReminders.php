<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Models\RecurringPrivateLessonCharge;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class SendRecurringPrivateLessonPaymentReminders
{
    public function __construct(private SendRecurringPrivateLessonEmail $emails) {}

    /** @return array{charges_processed: int, emails_queued: int} */
    public function handle(?CarbonInterface $dateTime = null): array
    {
        $displayTimezone = (string) config('app.display_timezone', 'America/New_York');
        $storageTimezone = (string) config('app.timezone');
        $today = CarbonImmutable::instance($dateTime ?? now())->timezone($displayTimezone)->startOfDay();
        $chargesProcessed = 0;
        $emailsQueued = 0;

        foreach ([7 => 'seven_day_reminder_sent_at', 2 => 'two_day_reminder_sent_at'] as $days => $timestampColumn) {
            $target = $today->addDays($days);
            $start = $target->startOfDay()->timezone($storageTimezone);
            $end = $target->endOfDay()->timezone($storageTimezone);

            RecurringPrivateLessonCharge::query()
                ->whereIn('status', [
                    RecurringPrivateLessonChargeStatus::Scheduled,
                    RecurringPrivateLessonChargeStatus::Billed,
                ])
                ->whereNull($timestampColumn)
                ->whereHas('event', fn (Builder $query): Builder => $query
                    ->whereNull('cancelled_at')
                    ->whereBetween('start_time', [$start, $end]))
                ->with(['event', 'recurringPrivateLesson'])
                ->lazyById()
                ->each(function (RecurringPrivateLessonCharge $charge) use (
                    $days,
                    $timestampColumn,
                    &$chargesProcessed,
                    &$emailsQueued,
                ): void {
                    $emailsQueued += $this->emails->paymentReminder($charge, $days);
                    $charge->update([$timestampColumn => now()]);
                    $chargesProcessed++;
                });
        }

        return [
            'charges_processed' => $chargesProcessed,
            'emails_queued' => $emailsQueued,
        ];
    }
}
