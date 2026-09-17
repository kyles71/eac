<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ScheduleFrequency;
use App\Exceptions\ScheduleRecurrenceLimitExceededException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final readonly class ScheduleRecurrenceService
{
    public const int MAX_OCCURRENCES = 366;

    /**
     * @return list<array{start_time: Carbon, end_time: Carbon|null}>
     */
    public function recurringIntervals(
        CarbonInterface|string $startsAt,
        CarbonInterface|string|null $endsAt,
        CarbonInterface|string $repeatThrough,
        ScheduleFrequency $frequency,
    ): array {
        $firstStart = Carbon::parse($startsAt);
        $firstEnd = filled($endsAt) ? Carbon::parse($endsAt) : null;
        $durationInSeconds = $firstEnd instanceof CarbonInterface
            ? $firstStart->diffInSeconds($firstEnd, false)
            : null;
        $inclusiveRepeatThrough = Carbon::parse($repeatThrough)
            ->timezone($this->displayTimezone())
            ->endOfDay()
            ->timezone((string) config('app.timezone'));
        $nextStart = $this->nextOccurrenceStart($firstStart, $frequency);
        $occurrenceCount = 1;
        $intervals = [];

        while ($nextStart->lte($inclusiveRepeatThrough)) {
            if ($occurrenceCount >= self::MAX_OCCURRENCES) {
                throw new ScheduleRecurrenceLimitExceededException(self::MAX_OCCURRENCES);
            }

            $intervals[] = [
                'start_time' => $nextStart,
                'end_time' => $durationInSeconds === null
                    ? null
                    : $nextStart->copy()->addSeconds($durationInSeconds),
            ];
            $occurrenceCount++;
            $nextStart = $this->nextOccurrenceStart($nextStart, $frequency);
        }

        return $intervals;
    }

    private function nextOccurrenceStart(Carbon $start, ScheduleFrequency $frequency): Carbon
    {
        $displayStart = $start->copy()->timezone($this->displayTimezone());

        return (match ($frequency) {
            ScheduleFrequency::Daily => $displayStart->addDay(),
            ScheduleFrequency::Weekly => $displayStart->addWeek(),
            ScheduleFrequency::Biweekly => $displayStart->addWeeks(2),
            ScheduleFrequency::Monthly => $displayStart->addMonthNoOverflow(),
        })->timezone((string) config('app.timezone'));
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
