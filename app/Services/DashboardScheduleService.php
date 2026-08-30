<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Models\Holiday;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class DashboardScheduleService
{
    /**
     * @return EloquentCollection<int, Calendar>
     */
    public function accessibleCalendars(User $user): EloquentCollection
    {
        return Calendar::query()
            ->visibleTo($user)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function upcoming(User $user, Calendar $calendar, CarbonInterface $startsAt, CarbonInterface $endsAt): Collection
    {
        $accessibleCalendars = $this->accessibleCalendars($user);
        $databaseTimezone = (string) config('app.timezone', 'UTC');
        $startsAt = $startsAt->copy()->timezone($databaseTimezone);
        $endsAt = $endsAt->copy()->timezone($databaseTimezone);

        $events = Event::query()
            ->with(['calendar', 'course.tags'])
            ->overlapping($startsAt, $endsAt)
            ->visibleOnCalendar($calendar, $user)
            ->orderBy('events.start_time')
            ->get()
            ->map(function (Event $event) use ($accessibleCalendars, $calendar, $user): array {
                $displayCalendar = $this->displayCalendarForEvent($event, $calendar, $accessibleCalendars);

                return [
                    'id' => $event->id,
                    'title' => $event->isCancelled() ? "Cancelled: {$event->name}" : $event->name,
                    'starts_at' => $event->start_time,
                    'ends_at' => $event->end_time,
                    'calendar' => $displayCalendar?->name,
                    'color' => $event->isCancelled() ? '#6b7280' : $displayCalendar?->background_color,
                    'is_holiday' => false,
                    'is_cancelled' => $event->isCancelled(),
                    'is_editable' => $user->can('update', $event),
                ];
            });

        return $events
            ->concat($this->holidays($startsAt, $endsAt))
            ->sortBy(fn (array $item): string => $item['starts_at']->toDateTimeString())
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fullCalendarEvents(User $user, Calendar $calendar, CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        return $this->upcoming($user, $calendar, $startsAt, $endsAt)
            ->map(fn (array $item): array => [
                'id' => $item['is_holiday'] ? "holiday-{$item['id']}" : $item['id'],
                'title' => $item['title'],
                'start' => $item['is_holiday']
                    ? $item['starts_at']->toDateString()
                    : $this->calendarTimestamp($item['starts_at']),
                'end' => $item['is_holiday']
                    ? $item['ends_at']->copy()->addDay()->toDateString()
                    : $this->calendarTimestamp($item['ends_at']),
                'allDay' => $item['is_holiday'],
                'backgroundColor' => $item['color'],
                'borderColor' => $item['color'],
                'editable' => ! $item['is_holiday'] && ! $item['is_cancelled'] && $item['is_editable'],
                'startEditable' => ! $item['is_holiday'] && ! $item['is_cancelled'] && $item['is_editable'],
                'durationEditable' => ! $item['is_holiday'] && ! $item['is_cancelled'] && $item['is_editable'],
                'extendedProps' => [
                    'isHoliday' => $item['is_holiday'],
                    'isCancelled' => $item['is_cancelled'],
                ],
            ])
            ->all();
    }

    /**
     * @param  EloquentCollection<int, Calendar>  $accessibleCalendars
     */
    private function displayCalendarForEvent(Event $event, Calendar $selectedCalendar, EloquentCollection $accessibleCalendars): ?Calendar
    {
        if (! $selectedCalendar->isMyCalendar()) {
            return $selectedCalendar;
        }

        if ($event->course instanceof Course) {
            $courseCalendarSlugs = $event->course
                ->tags
                ->where('type', Course::CALENDAR_TAG_TYPE)
                ->pluck('name');

            $routedCalendar = $accessibleCalendars
                ->where('slug', '!=', Calendar::SLUG_MY)
                ->first(fn (Calendar $calendar): bool => $courseCalendarSlugs->contains($calendar->slug));

            if ($routedCalendar instanceof Calendar) {
                return $routedCalendar;
            }
        }

        $eventCalendar = $event->calendar;

        return $eventCalendar instanceof Calendar ? $eventCalendar : null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function holidays(CarbonInterface $startsAt, CarbonInterface $endsAt): Collection
    {
        $startsOn = $startsAt->copy()->timezone($this->displayTimezone())->toDateString();
        $endsOn = $endsAt->copy()->subMicrosecond()->timezone($this->displayTimezone())->toDateString();

        return Holiday::query()
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Holiday $holiday): array => $this->holidayEntry($holiday));
    }

    /**
     * @return array<string, mixed>
     */
    private function holidayEntry(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'title' => $holiday->name,
            'starts_at' => $holiday->starts_on->startOfDay(),
            'ends_at' => $holiday->ends_on->startOfDay(),
            'calendar' => null,
            'color' => '#dc2626',
            'is_holiday' => true,
            'is_cancelled' => false,
            'is_editable' => false,
        ];
    }

    private function calendarTimestamp(?CarbonInterface $dateTime): ?string
    {
        return $dateTime?->copy()
            ->timezone($this->displayTimezone())
            ->toIso8601String();
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
