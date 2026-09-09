<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Traits;

use App\Enums\ScheduleFrequency;
use App\Services\HolidayConflictService;
use App\Services\ScheduleRecurrenceService;
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

        $intervals = app(ScheduleRecurrenceService::class)->recurringIntervals(
            startsAt: $data[$start_field],
            endsAt: $data[$end_field] ?? null,
            repeatThrough: $repeat_through,
            frequency: $repeat_frequency,
        );
        $intervals = app(HolidayConflictService::class)->withoutConflicts(
            $intervals,
            $data['course_id'] ?? null,
        );

        foreach ($intervals as $interval) {
            $data[$start_field] = $interval['start_time']->toDateTimeString();

            if ($interval['end_time'] instanceof Carbon) {
                $data[$end_field] = $interval['end_time']->toDateTimeString();
            }

            $return[] = $create_method($data);
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

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
