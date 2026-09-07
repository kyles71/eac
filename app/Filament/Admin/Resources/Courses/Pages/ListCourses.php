<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Pages;

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Filament\Admin\Resources\Traits\HasRecurring;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Services\HolidayConflictService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ListCourses extends ListRecords
{
    use HasRecurring;

    protected static string $resource = CourseResource::class;

    private ?CarbonInterface $courseStartsAt = null;

    private ?int $courseDurationMinutes = null;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(),
            'active' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => self::activeCoursesQuery($query)),
            'my_active' => Tab::make('My Active')
                ->modifyQueryUsing(fn (Builder $query): Builder => self::activeCoursesQuery($query)
                    ->whereHas('teachers', fn (Builder $query): Builder => $query->whereKey(auth()->id()))),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'my_active';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => $this->prepareCourseCreateData($data))
                ->after(fn (CreateAction $action): array => $this->createCourseEvents($action)),
        ];
    }

    /**
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    private static function activeCoursesQuery(Builder $query): Builder
    {
        return $query->notConcluded();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareCourseCreateData(array $data): array
    {
        $this->courseStartsAt = $this->normalizeCourseStartsAt($data['start_time'] ?? null);
        $this->courseDurationMinutes = $this->normalizeCourseDuration($data['duration'] ?? null);

        unset($data['start_time'], $data['duration']);

        return $this->prepRecurringData($data);
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

        return DB::transaction(function () use ($eventData): array {
            $createEvent = function (array $data): Event {
                $event = Event::query()->create($data);
                app(ManageEventTeacherAssignments::class)->initializeCourseEvent($event);

                return $event;
            };
            $events = app(HolidayConflictService::class)->conflictingHolidayFor(
                $eventData['start_time'],
                $eventData['end_time'],
                $eventData['course_id'],
            ) === null
                ? [$createEvent($eventData)]
                : [];

            return [
                ...$events,
                ...$this->createRecurring(
                    $eventData,
                    $this->repeat_through,
                    $this->repeat_frequency,
                    $createEvent,
                ),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function courseEventData(Course $course): array
    {
        if (! $this->courseStartsAt instanceof CarbonInterface || $this->courseDurationMinutes === null) {
            return [];
        }

        return [
            'name' => $course->name,
            'description' => $course->description,
            'start_time' => $this->courseStartsAt->toDateTimeString(),
            'end_time' => $this->courseStartsAt->copy()->addMinutes($this->courseDurationMinutes)->toDateTimeString(),
            'calendar_id' => $this->courseCalendarId($course),
            'course_id' => $course->id,
        ];
    }

    private function normalizeCourseStartsAt(mixed $startsAt): ?CarbonInterface
    {
        if ($startsAt instanceof CarbonInterface) {
            return $startsAt;
        }

        if (blank($startsAt)) {
            return null;
        }

        return CarbonImmutable::parse((string) $startsAt);
    }

    private function normalizeCourseDuration(mixed $duration): ?int
    {
        if (! is_numeric($duration)) {
            return null;
        }

        $minutes = (int) $duration;

        return $minutes > 0 ? $minutes : null;
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
