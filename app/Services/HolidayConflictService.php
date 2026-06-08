<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\HolidayEventScope;
use App\Models\Event;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class HolidayConflictService
{
    /**
     * @return Builder<Event>
     */
    public function conflictingEvents(Holiday $holiday): Builder
    {
        return $this->conflictingEventsFor(
            $holiday->starts_on,
            $holiday->ends_on,
            $holiday->scope,
        );
    }

    /**
     * @return Builder<Event>
     */
    public function conflictingEventsFor(
        CarbonInterface|string|null $startsOn,
        CarbonInterface|string|null $endsOn,
        HolidayEventScope|string|null $scope,
    ): Builder {
        $query = Event::query();
        $scope = $this->normalizeScope($scope);
        $boundaries = $this->holidayBoundaries($startsOn, $endsOn);

        if ($scope === null || $boundaries === null) {
            return $query->whereRaw('0 = 1');
        }

        [$startsAt, $endsAt] = $boundaries;

        return $query
            ->overlapping($startsAt, $endsAt)
            ->when(
                $scope === HolidayEventScope::CourseClassesOnly,
                fn (Builder $query): Builder => $query->whereNotNull('course_id'),
            );
    }

    public function conflictingEventCountFor(
        CarbonInterface|string|null $startsOn,
        CarbonInterface|string|null $endsOn,
        HolidayEventScope|string|null $scope,
    ): int {
        return $this->conflictingEventsFor($startsOn, $endsOn, $scope)->count();
    }

    public function conflictingHoliday(Event $event): ?Holiday
    {
        return $this->conflictingHolidayFor(
            $event->start_time,
            $event->end_time,
            $event->course_id,
        );
    }

    public function conflictingHolidayFor(
        CarbonInterface|string|null $startsAt,
        CarbonInterface|string|null $endsAt,
        mixed $courseId,
    ): ?Holiday {
        $eventDates = $this->eventDateRange($startsAt, $endsAt);

        if ($eventDates === null) {
            return null;
        }

        [$startsOn, $endsOn] = $eventDates;

        return Holiday::query()
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->where(function (Builder $query) use ($courseId): void {
                $query->where('scope', HolidayEventScope::AllEvents->value);

                if (is_numeric($courseId)) {
                    $query->orWhere('scope', HolidayEventScope::CourseClassesOnly->value);
                }
            })
            ->orderBy('starts_on')
            ->orderBy('id')
            ->first();
    }

    public function deleteConflictingEvents(Holiday $holiday): int
    {
        $deleted = 0;

        $this->conflictingEvents($holiday)
            ->eachById(function (Event $event) use (&$deleted): void {
                if ($event->delete()) {
                    $deleted++;
                }
            });

        return $deleted;
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    private function holidayBoundaries(
        CarbonInterface|string|null $startsOn,
        CarbonInterface|string|null $endsOn,
    ): ?array {
        if (blank($startsOn) || blank($endsOn)) {
            return null;
        }

        $displayTimezone = $this->displayTimezone();
        $startsAt = CarbonImmutable::parse($this->dateString($startsOn), $displayTimezone)
            ->startOfDay()
            ->timezone(config('app.timezone'));
        $endsAt = CarbonImmutable::parse($this->dateString($endsOn), $displayTimezone)
            ->addDay()
            ->startOfDay()
            ->timezone(config('app.timezone'));

        if ($endsAt->lte($startsAt)) {
            return null;
        }

        return [$startsAt, $endsAt];
    }

    /**
     * @return array{string, string}|null
     */
    private function eventDateRange(
        CarbonInterface|string|null $startsAt,
        CarbonInterface|string|null $endsAt,
    ): ?array {
        if (blank($startsAt)) {
            return null;
        }

        $start = CarbonImmutable::parse($startsAt)->timezone($this->displayTimezone());
        $end = filled($endsAt)
            ? CarbonImmutable::parse($endsAt)->timezone($this->displayTimezone())
            : $start;

        if ($end->lte($start)) {
            $end = $start->addMicrosecond();
        }

        return [
            $start->toDateString(),
            $end->subMicrosecond()->toDateString(),
        ];
    }

    private function normalizeScope(HolidayEventScope|string|null $scope): ?HolidayEventScope
    {
        if ($scope instanceof HolidayEventScope) {
            return $scope;
        }

        if (! is_string($scope)) {
            return null;
        }

        return HolidayEventScope::tryFrom($scope);
    }

    private function dateString(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : $date;
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
