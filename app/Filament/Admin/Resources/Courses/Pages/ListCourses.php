<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Pages;

use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Services\HolidayConflictService;
use Carbon\CarbonInterface;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCourses extends ListRecords
{
    use HasRecurring;

    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => $this->prepRecurringData($data))
                ->after(fn (CreateAction $action): array => $this->createCourseEvents($action)),
        ];
    }

    /**
     * @return array<int, Event>
     */
    private function createCourseEvents(CreateAction $action): array
    {
        $record = $action->getRecord();

        if (! $record instanceof Course) {
            return [];
        }

        $eventData = $this->courseEventData($record);

        if ($eventData === []) {
            return [];
        }

        $events = app(HolidayConflictService::class)->conflictingHolidayFor(
            $eventData['start_time'],
            $eventData['end_time'],
            $eventData['course_id'],
        ) === null
            ? [Event::query()->create($eventData)]
            : [];

        return [
            ...$events,
            ...$this->createRecurring(
                $eventData,
                $this->repeat_through,
                $this->repeat_frequency,
                fn (array $data): Event => Event::query()->create($data),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courseEventData(Course $course): array
    {
        if (! $course->start_time instanceof CarbonInterface) {
            return [];
        }

        return [
            'name' => $course->name,
            'description' => $course->description,
            'start_time' => $course->start_time->toDateTimeString(),
            'end_time' => $course->start_time->copy()->addMinutes($course->duration)->toDateTimeString(),
            'calendar_id' => $this->courseCalendarId($course),
            'course_id' => $course->id,
        ];
    }

    private function courseCalendarId(Course $course): ?int
    {
        $course->loadMissing('tags');

        $calendarSlug = $course
            ->tagsWithType(Course::CALENDAR_TAG_TYPE)
            ->pluck('name')
            ->first() ?? Calendar::SLUG_EAC;

        $calendarId = Calendar::query()
            ->where('slug', $calendarSlug)
            ->value('id');

        return is_int($calendarId) ? $calendarId : null;
    }
}
