<?php

declare(strict_types=1);

namespace App\Actions\RecurringPrivateLessons;

use App\Enums\CourseSemester;
use App\Enums\RecurringPrivateLessonStatus;
use App\Enums\ScheduleFrequency;
use App\Models\AcademicTerm;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\RecurringPrivateLesson;
use App\Models\Student;
use App\Models\User;
use App\Services\HolidayConflictService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class CreateRecurringPrivateLesson
{
    public function __construct(
        private HolidayConflictService $holidayConflictService,
        private SynchronizeRecurringPrivateLessonCharges $synchronizeCharges,
    ) {}

    /** @param list<int> $teacherIds */
    public function handle(
        User $household,
        Student $student,
        array $teacherIds,
        string $name,
        ?string $description,
        CourseSemester $semester,
        int $lessonPrice,
        CarbonInterface $startsAt,
        int $durationMinutes,
        CarbonInterface $repeatThrough,
        ScheduleFrequency $frequency,
    ): RecurringPrivateLesson {
        if ($student->user_id !== $household->id) {
            throw new InvalidArgumentException('The selected dancer does not belong to this household.');
        }

        if (! in_array($frequency, [ScheduleFrequency::Weekly, ScheduleFrequency::Biweekly], true)) {
            throw new InvalidArgumentException('Recurring private lessons must repeat weekly or biweekly.');
        }

        if ($teacherIds === []) {
            throw new InvalidArgumentException('At least one teacher is required.');
        }

        if ($lessonPrice < 1 || $durationMinutes < 1) {
            throw new InvalidArgumentException('The lesson price and duration must be greater than zero.');
        }

        $displayTimezone = (string) config('app.display_timezone', 'America/New_York');
        $firstStart = CarbonImmutable::instance($startsAt)
            ->timezone($displayTimezone)
            ->startOfMinute();
        $lastDate = CarbonImmutable::instance($repeatThrough)->timezone($displayTimezone)->endOfDay();

        if (! $firstStart->gt(now($displayTimezone)->addDay())) {
            throw new InvalidArgumentException('Recurring private lessons must be scheduled more than 24 hours in advance.');
        }

        if ($lastDate->lt($firstStart)) {
            throw new InvalidArgumentException('The repeat-through date must be on or after the first lesson.');
        }

        $academicTerm = AcademicTerm::query()
            ->where('semester', $semester)
            ->where('year', $firstStart->year)
            ->firstOrFail();

        return DB::transaction(function () use (
            $academicTerm,
            $household,
            $student,
            $teacherIds,
            $name,
            $description,
            $lessonPrice,
            $firstStart,
            $durationMinutes,
            $lastDate,
            $frequency,
        ): RecurringPrivateLesson {
            $course = Course::query()->create([
                'name' => $name,
                'description' => $description,
                'academic_term_id' => $academicTerm->id,
                'capacity' => 1,
                'is_private' => true,
            ]);
            $course->syncTagsWithType([Calendar::SLUG_STAFF], Course::CALENDAR_TAG_TYPE);
            $course->teachers()->sync($teacherIds);

            $recurringPrivateLesson = RecurringPrivateLesson::query()->create([
                'course_id' => $course->id,
                'user_id' => $household->id,
                'student_id' => $student->id,
                'lesson_price' => $lessonPrice,
                'status' => RecurringPrivateLessonStatus::Active,
            ]);

            Enrollment::query()->create([
                'course_id' => $course->id,
                'user_id' => $household->id,
                'student_id' => $student->id,
            ]);

            $calendarId = Calendar::query()->where('slug', Calendar::SLUG_STAFF)->value('id');
            $occurrence = $firstStart;

            while ($occurrence->lte($lastDate)) {
                $endsAt = $occurrence->addMinutes($durationMinutes);

                if ($this->holidayConflictService->conflictingHolidayFor(
                    $occurrence,
                    $endsAt,
                    $course->id,
                ) === null) {
                    Event::query()->create([
                        'name' => $course->name,
                        'description' => $course->description,
                        'start_time' => $occurrence->timezone(config('app.timezone'))->toDateTimeString(),
                        'end_time' => $endsAt->timezone(config('app.timezone'))->toDateTimeString(),
                        'calendar_id' => is_int($calendarId) ? $calendarId : null,
                        'course_id' => $course->id,
                    ]);
                }

                $occurrence = $occurrence->addWeeks(
                    $frequency === ScheduleFrequency::Weekly ? 1 : 2,
                );
            }

            $this->synchronizeCharges->handle($recurringPrivateLesson);

            return $recurringPrivateLesson->refresh()->load([
                'course.events',
                'course.teachers',
                'billingPeriods.charges.product',
            ]);
        });
    }
}
