<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Traits;

use App\Enums\ScheduleFrequency;
use App\Services\HolidayConflictService;
use Carbon\Carbon;
use Closure;

trait HasRecurring
{
    private ?Carbon $repeat_through = null;

    private ?ScheduleFrequency $repeat_frequency = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepRecurringData(array $data): array
    {
        $this->repeat_frequency = $this->normalizeRepeatFrequency($data['repeat_frequency'] ?? null);
        $this->repeat_through = $this->parseRepeatThrough($data['repeat_through'] ?? null);

        unset($data['repeat_frequency'], $data['repeat_through']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    public function createRecurring(
        array $data,
        ?Carbon $repeat_through,
        ?ScheduleFrequency $repeat_frequency,
        Closure $create_method,
        string $start_field = 'start_time',
        string $end_field = 'end_time',
    ): array {
        $return = [];

        if (! $repeat_frequency instanceof ScheduleFrequency || ! $repeat_through instanceof Carbon) {
            return $return;
        }

        if (blank($data[$start_field] ?? null)) {
            return $return;
        }

        $repeatThrough = $this->inclusiveRepeatThrough($repeat_through);
        $firstStart = Carbon::parse($data[$start_field]);
        $firstEnd = filled($data[$end_field] ?? null)
            ? Carbon::parse($data[$end_field])
            : null;
        $durationInSeconds = $firstEnd instanceof Carbon
            ? $firstStart->diffInSeconds($firstEnd, false)
            : null;
        $nextStart = $this->nextOccurrenceStart($firstStart, $repeat_frequency);

        while ($nextStart->lte($repeatThrough)) {
            $data[$start_field] = $nextStart->toDateTimeString();

            if ($durationInSeconds !== null) {
                $data[$end_field] = $nextStart->copy()
                    ->addSeconds($durationInSeconds)
                    ->toDateTimeString();
            }

            if (app(HolidayConflictService::class)->conflictingHolidayFor(
                $data[$start_field],
                $data[$end_field] ?? null,
                $data['course_id'] ?? null,
            ) === null) {
                $return[] = $create_method($data);
            }

            $nextStart = $this->nextOccurrenceStart($nextStart, $repeat_frequency);
        }

        return $return;
    }

    private function normalizeRepeatFrequency(mixed $frequency): ?ScheduleFrequency
    {
        if ($frequency instanceof ScheduleFrequency) {
            return $frequency;
        }

        if (! is_string($frequency) || blank($frequency)) {
            return null;
        }

        return ScheduleFrequency::tryFrom($frequency);
    }

    private function parseRepeatThrough(mixed $repeatThrough): ?Carbon
    {
        if ($repeatThrough instanceof Carbon) {
            return $repeatThrough;
        }

        if (blank($repeatThrough)) {
            return null;
        }

        return Carbon::parse($repeatThrough, $this->displayTimezone());
    }

    private function inclusiveRepeatThrough(Carbon $repeatThrough): Carbon
    {
        return $repeatThrough
            ->copy()
            ->timezone($this->displayTimezone())
            ->endOfDay()
            ->timezone(config('app.timezone'));
    }

    private function nextOccurrenceStart(Carbon $start, ScheduleFrequency $frequency): Carbon
    {
        return match ($frequency) {
            ScheduleFrequency::Daily => $start->copy()->addDay(),
            ScheduleFrequency::Weekly => $start->copy()->addWeek(),
            ScheduleFrequency::Biweekly => $start->copy()->addWeeks(2),
            ScheduleFrequency::Monthly => $start->copy()->addMonthNoOverflow(),
        };
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
