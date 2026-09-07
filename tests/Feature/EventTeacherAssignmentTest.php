<?php

declare(strict_types=1);

use App\Actions\Events\ManageEventSubstitution;
use App\Actions\Events\ManageEventTeacherAssignments;
use App\Actions\RecurringPrivateLessons\CreateRecurringPrivateLesson;
use App\Enums\CourseSemester;
use App\Enums\CourseTeacherAssignmentStrategy;
use App\Enums\EventSubstituteCoverageStatus;
use App\Enums\EventSubstituteRequestStatus;
use App\Enums\EventTeacherAssignmentMode;
use App\Enums\ReportKey;
use App\Enums\ScheduleFrequency;
use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventSubstituteRequest;
use App\Models\Holiday;
use App\Models\Student;
use App\Models\User;
use App\Services\Reports\InstructorReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('persists an ordered course rotation and continues it when meetings are added later', function (): void {
    $firstTeacher = User::factory()->isTeacher()->create();
    $secondTeacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $assignments = app(ManageEventTeacherAssignments::class);

    $assignments->updateCourseRoster(
        $course,
        [$secondTeacher->id, $firstTeacher->id],
        CourseTeacherAssignmentStrategy::RotateTeachers,
    );

    $events = collect([1, 2, 3])->map(function (int $day) use ($assignments, $course): Event {
        $event = Event::query()->create([
            'name' => "Meeting {$day}",
            'course_id' => $course->id,
            'start_time' => now()->addDays($day)->setTime(10, 0),
            'end_time' => now()->addDays($day)->setTime(11, 0),
        ]);

        return $assignments->initializeCourseEvent($event);
    });

    expect($course->refresh()->teacher_assignment_strategy)->toBe(CourseTeacherAssignmentStrategy::RotateTeachers)
        ->and($course->teachers->pluck('id')->all())->toBe([$secondTeacher->id, $firstTeacher->id])
        ->and($course->teachers->pluck('pivot.rotation_position')->all())->toBe([1, 2])
        ->and($events->pluck('teacher_rotation_sequence')->all())->toBe([1, 2, 3])
        ->and($events->map(fn (Event $event): array => $event->teachers->modelKeys())->all())->toBe([
            [$secondTeacher->id],
            [$firstTeacher->id],
            [$secondTeacher->id],
        ]);

    $laterEvent = Event::query()->create([
        'name' => 'Later Addition',
        'course_id' => $course->id,
        'start_time' => now()->addDays(10)->setTime(10, 0),
        'end_time' => now()->addDays(10)->setTime(11, 0),
    ]);
    $assignments->initializeCourseEvent($laterEvent);

    expect($laterEvent->refresh()->teacher_rotation_sequence)->toBe(4)
        ->and($laterEvent->teachers->modelKeys())->toBe([$firstTeacher->id]);
});

it('does not advance a recurring private lesson rotation for holiday-skipped occurrences', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/New_York'));
    Holiday::factory()->create([
        'starts_on' => '2026-08-17',
        'ends_on' => '2026-08-17',
    ]);
    $household = User::factory()->create();
    $student = Student::factory()->for($household)->create();
    $firstTeacher = User::factory()->isTeacher()->create();
    $secondTeacher = User::factory()->isTeacher()->create();

    $series = app(CreateRecurringPrivateLesson::class)->handle(
        household: $household,
        student: $student,
        teacherIds: [$firstTeacher->id, $secondTeacher->id],
        name: 'Rotating Private Lesson',
        description: null,
        semester: CourseSemester::Fall,
        lessonPrice: 5000,
        startsAt: CarbonImmutable::parse('2026-08-10 17:00', 'America/New_York'),
        durationMinutes: 60,
        repeatThrough: CarbonImmutable::parse('2026-08-31', 'America/New_York'),
        frequency: ScheduleFrequency::Weekly,
        teacherAssignmentStrategy: CourseTeacherAssignmentStrategy::RotateTeachers,
    );
    $events = $series->course->events()->with('teachers')->orderBy('start_time')->get();

    expect($events->pluck('start_time')->map->format('Y-m-d')->all())->toBe([
        '2026-08-10',
        '2026-08-24',
        '2026-08-31',
    ])->and($events->pluck('teacher_rotation_sequence')->all())->toBe([1, 2, 3])
        ->and($events->map(fn (Event $event): array => $event->teachers->modelKeys())->all())->toBe([
            [$firstTeacher->id],
            [$secondTeacher->id],
            [$firstTeacher->id],
        ]);
});

it('updates only future untouched course-default events when the course roster changes', function (): void {
    $originalTeacher = User::factory()->isTeacher()->create();
    $replacementTeacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $assignments = app(ManageEventTeacherAssignments::class);
    $assignments->updateCourseRoster(
        $course,
        [$originalTeacher->id],
        CourseTeacherAssignmentStrategy::AllTeachers,
    );

    $pastEvent = Event::query()->create([
        'name' => 'Past',
        'course_id' => $course->id,
        'start_time' => now()->subDays(2),
        'end_time' => now()->subDays(2)->addHour(),
    ]);
    $defaultEvent = Event::query()->create([
        'name' => 'Default',
        'course_id' => $course->id,
        'start_time' => now()->addDays(2),
        'end_time' => now()->addDays(2)->addHour(),
    ]);
    $customEvent = Event::query()->create([
        'name' => 'Custom',
        'course_id' => $course->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(3)->addHour(),
    ]);
    $assignments->initializeCourseEvent($pastEvent);
    $assignments->initializeCourseEvent($defaultEvent);
    $assignments->initializeCourseEvent($customEvent);
    $assignments->assignCustom($customEvent, [$originalTeacher->id]);

    $assignments->updateCourseRoster(
        $course,
        [$replacementTeacher->id],
        CourseTeacherAssignmentStrategy::AllTeachers,
    );

    expect($pastEvent->refresh()->teachers->modelKeys())->toBe([$originalTeacher->id])
        ->and($defaultEvent->refresh()->teachers->modelKeys())->toBe([$replacementTeacher->id])
        ->and($customEvent->refresh()->teachers->modelKeys())->toBe([$originalTeacher->id])
        ->and($customEvent->teacher_assignment_mode)->toBe(EventTeacherAssignmentMode::Custom);
});

it('rolls back a course roster synchronization when a new regular assignment conflicts', function (): void {
    $originalTeacher = User::factory()->isTeacher()->create();
    $busyTeacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $assignments = app(ManageEventTeacherAssignments::class);
    $assignments->updateCourseRoster($course, [$originalTeacher->id], CourseTeacherAssignmentStrategy::AllTeachers);
    $courseEvent = Event::query()->create([
        'name' => 'Course Event',
        'course_id' => $course->id,
        'start_time' => now()->addDays(2)->setTime(10, 0),
        'end_time' => now()->addDays(2)->setTime(11, 0),
    ]);
    $assignments->initializeCourseEvent($courseEvent);
    $conflict = Event::factory()->standalone()->create([
        'start_time' => now()->addDays(2)->setTime(10, 30),
        'end_time' => now()->addDays(2)->setTime(11, 30),
    ]);
    $assignments->assignCustom($conflict, [$busyTeacher->id]);

    expect(fn () => $assignments->updateCourseRoster(
        $course,
        [$busyTeacher->id],
        CourseTeacherAssignmentStrategy::AllTeachers,
    ))->toThrow(DomainException::class, 'already assigned');

    expect($course->refresh()->teachers->modelKeys())->toBe([$originalTeacher->id])
        ->and($courseEvent->refresh()->teachers->modelKeys())->toBe([$originalTeacher->id]);
});

it('rolls back an event time change that would double-book its confirmed substitute', function (): void {
    $regularTeacher = User::factory()->isTeacher()->create();
    $substitute = User::factory()->isTeacher()->create();
    $actor = auth()->user();
    $assignments = app(ManageEventTeacherAssignments::class);
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addDays(2)->setTime(10, 0),
        'end_time' => now()->addDays(2)->setTime(11, 0),
    ]);
    $assignments->assignCustom($event, [$regularTeacher->id]);

    expect($actor)->toBeInstanceOf(User::class);
    $request = app(ManageEventSubstitution::class)->requestSubstitute(
        $event,
        $regularTeacher,
        $substitute,
        $actor,
    );
    app(ManageEventSubstitution::class)->respond($request, $substitute, true);

    $conflictingEvent = Event::factory()->standalone()->create([
        'start_time' => now()->addDays(3)->setTime(10, 30),
        'end_time' => now()->addDays(3)->setTime(11, 30),
    ]);
    $assignments->assignCustom($conflictingEvent, [$substitute->id]);

    expect(fn () => DB::transaction(function () use ($assignments, $event, $regularTeacher): void {
        $event->update([
            'start_time' => now()->addDays(3)->setTime(10, 0),
            'end_time' => now()->addDays(3)->setTime(11, 0),
        ]);
        $assignments->assignCustom($event, [$regularTeacher->id]);
    }))->toThrow(DomainException::class, 'already assigned');

    expect($event->refresh()->start_time?->isSameDay(now()->addDays(2)))->toBeTrue();
});

it('tracks independent substitute coverage for each regular teacher on a co-taught event', function (): void {
    $firstRegular = User::factory()->isTeacher()->create();
    $secondRegular = User::factory()->isTeacher()->create();
    $firstSubstitute = User::factory()->isTeacher()->create();
    $secondSubstitute = User::factory()->isTeacher()->create();
    $actor = auth()->user();
    $event = Event::factory()->standalone()->create([
        'start_time' => now()->addDays(2),
        'end_time' => now()->addDays(2)->addHour(),
    ]);
    app(ManageEventTeacherAssignments::class)->assignCustom($event, [
        $firstRegular->id,
        $secondRegular->id,
    ]);

    expect($actor)->toBeInstanceOf(User::class);
    $firstRequest = app(ManageEventSubstitution::class)->requestSubstitute(
        $event,
        $firstRegular,
        $firstSubstitute,
        $actor,
    );
    $secondRequest = app(ManageEventSubstitution::class)->requestSubstitute(
        $event,
        $secondRegular,
        $secondSubstitute,
        $actor,
    );
    app(ManageEventSubstitution::class)->respond($firstRequest, $firstSubstitute, true);
    app(ManageEventSubstitution::class)->respond($secondRequest, $secondSubstitute, true);

    expect($event->refresh()->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::Confirmed)
        ->and($event->substituteCoverageLabel())->toContain('2/2 covered')
        ->and($event->activeSubstituteCoverages()->count())->toBe(2)
        ->and($event->coverageForTeacher($firstRegular)?->substitute_teacher_id)->toBe($firstSubstitute->id)
        ->and($event->coverageForTeacher($secondRegular)?->substitute_teacher_id)->toBe($secondSubstitute->id)
        ->and($event->teachers->modelKeys())->toEqualCanonicalizing([$firstRegular->id, $secondRegular->id]);

    app(ManageEventSubstitution::class)->requestRelease(
        $event->coverageForTeacher($firstRegular),
        $firstSubstitute,
        'No longer available.',
    );

    expect($event->refresh()->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::ReleaseRequested)
        ->and($event->coverageForTeacher($secondRegular)?->substitute_teacher_id)->toBe($secondSubstitute->id);
});

it('credits actual teachers for partial co-teacher coverage and includes standalone events in term reports', function (): void {
    $coveredRegular = User::factory()->isTeacher()->create();
    $remainingRegular = User::factory()->isTeacher()->create();
    $substitute = User::factory()->isTeacher()->create();
    $actor = auth()->user();
    $term = AcademicTerm::query()
        ->whereDate('starts_on', '<=', now()->addDays(5))
        ->whereDate('ends_on', '>=', now()->addDays(5))
        ->firstOrFail();
    $event = Event::factory()->standalone()->create([
        'name' => 'Standalone Private Lesson',
        'start_time' => now()->addDays(5)->setTime(13, 0),
        'end_time' => now()->addDays(5)->setTime(15, 0),
    ]);
    app(ManageEventTeacherAssignments::class)->assignCustom($event, [
        $coveredRegular->id,
        $remainingRegular->id,
    ]);

    expect($actor)->toBeInstanceOf(User::class);
    $request = app(ManageEventSubstitution::class)->requestSubstitute(
        $event,
        $coveredRegular,
        $substitute,
        $actor,
    );
    app(ManageEventSubstitution::class)->respond($request, $substitute, true);

    $dataset = app(InstructorReportService::class)->dataset(
        ReportKey::InstructorHoursSummary,
        $actor,
        [
            'academic_term_id' => ['value' => $term->id],
            'date_range' => [
                'from' => $term->starts_on->toDateString(),
                'through' => $term->ends_on->toDateString(),
            ],
        ],
    );
    $hoursByInstructor = collect($dataset->rows)->keyBy('instructor_name');

    expect($hoursByInstructor[$coveredRegular->fullName])->toMatchArray([
        'scheduled_hours' => 0.0,
        'sub_hours_needed' => 2.0,
    ])->and($hoursByInstructor[$remainingRegular->fullName]['scheduled_hours'])->toBe(2.0)
        ->and($hoursByInstructor[$substitute->fullName])->toMatchArray([
            'scheduled_hours' => 2.0,
            'sub_hours_covered' => 2.0,
        ]);
});

it('backfills legacy course staffing and preserves ambiguous substitute history', function (): void {
    $singleTeacher = User::factory()->isTeacher()->create();
    $firstCoTeacher = User::factory()->isTeacher()->create();
    $secondCoTeacher = User::factory()->isTeacher()->create();
    $substitute = User::factory()->isTeacher()->create();
    $singleCourse = Course::factory()->create();
    $singleCourse->teachers()->sync([$singleTeacher->id]);
    $multiCourse = Course::factory()->create();
    $multiCourse->teachers()->sync([$firstCoTeacher->id, $secondCoTeacher->id]);
    $singleEvent = Event::factory()->for($singleCourse)->create();
    $multiEvent = Event::factory()->for($multiCourse)->create();
    $standaloneEvent = Event::factory()->standalone()->create();
    $now = now();
    $pendingRequestId = DB::table('event_substitute_requests')->insertGetId([
        'event_id' => $singleEvent->id,
        'teacher_id' => $substitute->id,
        'status' => EventSubstituteRequestStatus::Pending->value,
        'created_at' => $now->copy()->subWeek(),
        'updated_at' => $now,
    ]);
    $acceptedRequestId = DB::table('event_substitute_requests')->insertGetId([
        'event_id' => $multiEvent->id,
        'teacher_id' => $substitute->id,
        'status' => EventSubstituteRequestStatus::Accepted->value,
        'responded_at' => $now->copy()->subDays(6),
        'created_at' => $now->copy()->subWeek(),
        'updated_at' => $now,
    ]);
    $migration = require database_path('migrations/2026_09_01_023353_migrate_legacy_event_teacher_and_substitute_data.php');

    DB::table('event_teacher_assignments')->delete();
    DB::table('event_substitute_coverages')->delete();
    $legacySubstituteEvents = collect([
        (object) [
            'id' => $singleEvent->id,
            'substitute_teacher_id' => null,
            'substitute_needed_at' => $now->copy()->subWeek(),
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'created_at' => $singleEvent->created_at,
            'updated_at' => $singleEvent->updated_at,
        ],
        (object) [
            'id' => $multiEvent->id,
            'substitute_teacher_id' => $substitute->id,
            'substitute_needed_at' => $now->copy()->subWeek(),
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'created_at' => $multiEvent->created_at,
            'updated_at' => $multiEvent->updated_at,
        ],
    ]);

    foreach (['backfillCourseTeacherOrder', 'backfillEventAssignmentsAndSequence'] as $methodName) {
        (new ReflectionMethod($migration, $methodName))->invoke($migration);
    }

    (new ReflectionMethod($migration, 'backfillSubstituteCoverage'))
        ->invoke($migration, $legacySubstituteEvents);

    $singleCoverage = DB::table('event_substitute_coverages')
        ->where('event_id', $singleEvent->id)
        ->sole();
    $ambiguousCoverage = DB::table('event_substitute_coverages')
        ->where('event_id', $multiEvent->id)
        ->sole();
    $pendingRequest = EventSubstituteRequest::query()->findOrFail($pendingRequestId);
    $acceptedRequest = EventSubstituteRequest::query()->findOrFail($acceptedRequestId);

    expect(Schema::hasColumn('events', 'substitute_teacher_id'))->toBeFalse()
        ->and(Schema::hasColumn('events', 'substitute_needed_at'))->toBeFalse()
        ->and($singleEvent->refresh()->teacher_assignment_mode)->toBe(EventTeacherAssignmentMode::CourseDefaults)
        ->and($multiEvent->refresh()->teacher_assignment_mode)->toBe(EventTeacherAssignmentMode::CourseDefaults)
        ->and($standaloneEvent->refresh()->teacher_assignment_mode)->toBe(EventTeacherAssignmentMode::Custom)
        ->and($singleEvent->teachers()->pluck('users.id')->all())->toBe([$singleTeacher->id])
        ->and($multiEvent->teachers()->pluck('users.id')->all())->toEqualCanonicalizing([
            $firstCoTeacher->id,
            $secondCoTeacher->id,
        ])
        ->and($singleCoverage->covered_teacher_id)->toBe($singleTeacher->id)
        ->and($singleCoverage->substitute_teacher_id)->toBeNull()
        ->and($ambiguousCoverage->covered_teacher_id)->toBeNull()
        ->and($ambiguousCoverage->substitute_teacher_id)->toBe($substitute->id)
        ->and($pendingRequest->refresh()->event_substitute_coverage_id)->toBe($singleCoverage->id)
        ->and($pendingRequest->status)->toBe(EventSubstituteRequestStatus::Pending)
        ->and($acceptedRequest->refresh()->event_substitute_coverage_id)->toBe($ambiguousCoverage->id)
        ->and($acceptedRequest->status)->toBe(EventSubstituteRequestStatus::Accepted);

    $multiEvent->update([
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);
    $owner = auth()->user();

    expect($owner)->toBeInstanceOf(User::class);
    app(ManageEventSubstitution::class)->recordHistoricalCorrection(
        $multiEvent,
        $substitute,
        $owner,
        'Recorded the previously unknown regular teacher.',
        $firstCoTeacher,
    );

    expect(DB::table('event_substitute_coverages')->where('id', $ambiguousCoverage->id)->value('covered_teacher_id'))
        ->toBe($firstCoTeacher->id)
        ->and(DB::table('event_substitute_coverages')
            ->where('event_id', $multiEvent->id)
            ->whereNull('covered_teacher_id')
            ->exists())->toBeFalse();
});
