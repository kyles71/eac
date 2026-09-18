<?php

declare(strict_types=1);

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Enums\EventTeacherAssignmentMode;
use App\Enums\ScheduleFrequency;
use App\Exceptions\TeacherScheduleConflictException;
use App\Filament\Admin\Resources\Courses\Pages\ViewCourse;
use App\Filament\Admin\Resources\Courses\RelationManagers\EventsRelationManager;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\RecurringPrivateLesson;
use App\Models\User;
use App\Services\EventScheduleConflictReviewService;
use App\Services\TeacherScheduleConflictService;
use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Saade\FilamentFullCalendar\Actions\CreateAction as CalendarCreateAction;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function (): void {
    foreach (Calendar::systemCalendarDefinitions() as $slug => $calendar) {
        Calendar::query()->updateOrCreate(['slug' => $slug], $calendar);
    }

    Filament::setCurrentPanel('admin');
});

it('discovers every relevant teacher conflict without changing existing overlap semantics', function (): void {
    $regularTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Martha',
        'last_name' => 'Graham',
    ]);
    $substituteAndAttendee = User::factory()->isTeacher()->create([
        'first_name' => 'Natalie',
        'last_name' => 'Cole',
    ]);
    $releasedTeacher = User::factory()->isTeacher()->create();
    $regularConflict = teacherScheduleEvent('Regular Class', '2027-01-15 18:00', '2027-01-15 19:00');
    $regularConflict->teachers()->attach($regularTeacher);
    $attendeeConflict = teacherScheduleEvent('Staff Meeting', '2027-01-15 18:15', '2027-01-15 18:45');
    EventAttendee::factory()->forUser($substituteAndAttendee)->create(['event_id' => $attendeeConflict->id]);
    $substituteConflict = teacherScheduleEvent('Covered Class', '2027-01-15 18:30', '2027-01-15 19:30');
    $substituteConflict->teachers()->attach($releasedTeacher);
    $substituteConflict->substituteCoverages()->create([
        'covered_teacher_id' => $releasedTeacher->id,
        'substitute_teacher_id' => $substituteAndAttendee->id,
        'needed_at' => now(),
    ]);
    $cancelledConflict = teacherScheduleEvent('Cancelled Class', '2027-01-15 18:30', '2027-01-15 19:30');
    $cancelledConflict->teachers()->attach($regularTeacher);
    $cancelledConflict->update(['cancelled_at' => now()]);
    $excludedAttendeeConflict = teacherScheduleEvent('Excluded Meeting', '2027-01-15 18:30', '2027-01-15 19:30');
    EventAttendee::factory()->forUser($regularTeacher)->create(['event_id' => $excludedAttendeeConflict->id]);
    $excludedAttendeeConflict->excludedUsers()->attach($regularTeacher);
    $boundaryEvent = teacherScheduleEvent('Boundary Class', '2027-01-15 19:00', '2027-01-15 20:00');
    $boundaryEvent->teachers()->attach($regularTeacher);
    $proposal = teacherScheduleEvent('Proposed Class', '2027-01-15 18:00', '2027-01-15 19:00');
    $proposal->teachers()->attach($regularTeacher);

    $conflicts = app(TeacherScheduleConflictService::class)->conflicts(
        $proposal,
        collect([$regularTeacher, $substituteAndAttendee, $releasedTeacher]),
    );
    $exception = new TeacherScheduleConflictException($conflicts);

    expect($conflicts->pluck('conflictingEvent.name')->all())->toBe([
        'Regular Class',
        'Staff Meeting',
        'Covered Class',
    ])->and($conflicts->pluck('teacher.id')->all())->toBe([
        $regularTeacher->id,
        $substituteAndAttendee->id,
        $substituteAndAttendee->id,
    ])->and($conflicts->pluck('conflictingEvent.name')->all())
        ->not->toContain('Cancelled Class', 'Excluded Meeting', 'Boundary Class', 'Proposed Class')
        ->and($exception->getMessage())
        ->toBe('2 teachers are already assigned to 3 overlapping events. Review the conflicts below.')
        ->and($exception->displayMessages())->toHaveCount(3);
});

it('keeps a conflicting list create open with details and allows an authorized confirmation', function (): void {
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Martha',
        'last_name' => 'Graham',
    ]);
    $conflictingEvent = teacherScheduleEvent('Busy Rehearsal', '2027-01-15 18:00', '2027-01-15 19:00');
    app(ManageEventTeacherAssignments::class)->assignCustom($conflictingEvent, [$teacher->id]);
    $data = teacherScheduleFormData('Overlapping Rehearsal', $teacher, '2027-01-15 18:30', '2027-01-15 19:30');

    $component = livewire(ListEvents::class)
        ->callAction(CreateAction::class, data: $data)
        ->assertHasActionErrors(['start_time'])
        ->assertSchemaComponentVisible('allow_teacher_schedule_conflicts')
        ->assertSet('mountedActions.0.data.teacher_schedule_conflicts.0', fn (mixed $detail): bool => is_string($detail)
            && str_contains($detail, 'Martha Graham')
            && str_contains($detail, 'Busy Rehearsal')
            && str_contains($detail, 'proposed occurrence'));

    expect($component->instance()->getErrorBag()->first('mountedActions.0.data.start_time'))
        ->toBe('Martha Graham is already assigned to the overlapping event "Busy Rehearsal".')
        ->and($component->get('mountedActions.0.data.teacher_schedule_conflicts.0'))
        ->toContain('proposed occurrence (Jan 15, 2027 6:30 PM–7:30 PM EST)')
        ->and(Event::query()->where('name', 'Overlapping Rehearsal')->exists())->toBeFalse();

    $component
        ->fillForm(['allow_teacher_schedule_conflicts' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $createdEvent = Event::query()->where('name', 'Overlapping Rehearsal')->firstOrFail();

    expect($createdEvent->teachers()->pluck('users.id')->all())->toBe([$teacher->id]);
});

it('hides the override from unauthorized users and rejects forged confirmation state', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $scheduler = User::factory()->create();
    $scheduler->givePermissionTo(['ViewAny:Event', 'View:Event', 'Create:Event']);
    $privateCourse = Course::factory()->create(['is_private' => true]);
    RecurringPrivateLesson::factory()->create(['course_id' => $privateCourse->id]);
    $conflictingEvent = teacherScheduleEvent(
        'Private Busy Rehearsal',
        '2027-01-15 18:00',
        '2027-01-15 19:00',
        $privateCourse,
    );
    app(ManageEventTeacherAssignments::class)->assignCustom($conflictingEvent, [$teacher->id]);
    actingAs($scheduler);

    $component = livewire(ListEvents::class)
        ->callAction(CreateAction::class, data: teacherScheduleFormData(
            'Forged Overlap',
            $teacher,
            '2027-01-15 18:30',
            '2027-01-15 19:30',
        ))
        ->assertHasActionErrors(['start_time'])
        ->assertSchemaComponentHidden('allow_teacher_schedule_conflicts')
        ->assertSet('mountedActions.0.data.teacher_schedule_conflicts.0', fn (mixed $detail): bool => is_string($detail)
            && str_contains($detail, 'do not have permission to view')
            && str_contains($detail, 'proposed occurrence')
            && ! str_contains($detail, 'Private Busy Rehearsal')
            && ! str_contains($detail, '6:00 PM'));

    $errorMessage = (string) $component->instance()->getErrorBag()->first('mountedActions.0.data.start_time');

    expect($errorMessage)
        ->toContain('do not have permission to view')
        ->and(str_contains($errorMessage, 'Private Busy Rehearsal'))->toBeFalse()
        ->and(str_contains($errorMessage, '6:00 PM'))->toBeFalse();

    $component
        ->set('mountedActions.0.data.allow_teacher_schedule_conflicts', true)
        ->callMountedAction()
        ->assertHasActionErrors(['start_time']);

    expect(Event::query()->where('name', 'Forged Overlap')->exists())->toBeFalse();
});

it('counts indistinguishable private conflicts and displays the proposed local time', function (): void {
    $teacher = User::factory()->isTeacher()->create([
        'first_name' => 'Alivia',
        'last_name' => 'Von',
    ]);
    $scheduler = User::factory()->create();
    $scheduler->givePermissionTo(['ViewAny:Event', 'View:Event', 'Create:Event']);
    $privateCourse = Course::factory()->create(['is_private' => true]);
    RecurringPrivateLesson::factory()->create(['course_id' => $privateCourse->id]);
    foreach ([
        ['Private One', '2027-08-31 10:01', '2027-08-31 11:01'],
        ['Private Two', '2027-08-31 10:26', '2027-08-31 11:26'],
        ['Private Three', '2027-08-31 10:56', '2027-08-31 16:56'],
    ] as [$name, $startsAt, $endsAt]) {
        teacherScheduleEvent($name, $startsAt, $endsAt, $privateCourse)
            ->teachers()
            ->attach($teacher);
    }

    actingAs($scheduler);

    $component = livewire(ListEvents::class)
        ->callAction(CreateAction::class, data: teacherScheduleFormData(
            'Overlap Test',
            $teacher,
            '2027-08-31 10:05',
            '2027-08-31 11:05',
        ))
        ->assertHasActionErrors(['start_time']);

    expect($component->get('mountedActions.0.data.teacher_schedule_conflicts'))
        ->toHaveCount(1)
        ->and($component->get('mountedActions.0.data.teacher_schedule_conflicts.0'))
        ->toBe('Alivia Von: 3 events you do not have permission to view conflict with the proposed occurrence (Aug 31, 2027 10:05 AM–11:05 AM EDT).')
        ->and($component->instance()->getErrorBag()->first('mountedActions.0.data.start_time'))
        ->toBe('1 teacher is already assigned to 3 overlapping events. Review the conflicts below.');
});

it('reviews selected standalone teachers when a course is cleared during edit', function (): void {
    $oldCourseTeacher = User::factory()->isTeacher()->create();
    $selectedTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Selected',
        'last_name' => 'Teacher',
    ]);
    $course = Course::factory()->create();
    $course->teachers()->sync([$oldCourseTeacher->id]);
    $event = teacherScheduleEvent('Course Event', '2027-01-16 18:00', '2027-01-16 19:00', $course);
    $conflictingEvent = teacherScheduleEvent('Selected Teacher Conflict', '2027-01-15 18:00', '2027-01-15 19:00');
    app(ManageEventTeacherAssignments::class)->assignCustom($conflictingEvent, [$selectedTeacher->id]);
    $data = teacherScheduleFormData(
        'Standalone Event',
        $selectedTeacher,
        '2027-01-15 18:30',
        '2027-01-15 19:30',
    );
    $data['teacher_assignment_mode'] = EventTeacherAssignmentMode::CourseDefaults->value;

    $component = livewire(ViewEvent::class, ['record' => $event->id])
        ->callAction(EditAction::class, data: $data)
        ->assertHasActionErrors(['start_time'])
        ->assertSchemaComponentVisible('allow_teacher_schedule_conflicts')
        ->assertSet('mountedActions.0.data.teacher_schedule_conflicts.0', fn (mixed $detail): bool => is_string($detail)
            && str_contains($detail, 'Selected Teacher')
            && str_contains($detail, 'Selected Teacher Conflict'));

    expect($event->refresh()->course_id)->toBe($course->id);

    $component
        ->fillForm(['allow_teacher_schedule_conflicts' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($event->refresh()->course_id)->toBeNull()
        ->and($event->teacher_assignment_mode)->toBe(EventTeacherAssignmentMode::Custom)
        ->and($event->teachers()->pluck('users.id')->all())->toBe([$selectedTeacher->id]);
});

it('enforces override authorization at the domain boundary', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $unauthorizedActor = User::factory()->create();
    $authorizedActor = User::factory()->create();
    $authorizedActor->givePermissionTo('OverrideScheduleConflicts:Event');
    $conflictingEvent = teacherScheduleEvent('Busy Rehearsal', '2027-01-15 18:00', '2027-01-15 19:00');
    $assignments = app(ManageEventTeacherAssignments::class);
    $assignments->assignCustom($conflictingEvent, [$teacher->id]);
    $proposal = teacherScheduleEvent('Domain Overlap', '2027-01-15 18:30', '2027-01-15 19:30');

    expect(fn () => $assignments->assignCustom($proposal, [$teacher->id], $unauthorizedActor))
        ->toThrow(AuthorizationException::class);

    $assignments->assignCustom($proposal, [$teacher->id], $authorizedActor);

    expect($proposal->refresh()->teachers()->pluck('users.id')->all())->toBe([$teacher->id]);
});

it('reviews a later recurring conflict and applies one confirmation to the whole submission', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $laterConflict = teacherScheduleEvent('Second Week Conflict', '2027-01-08 18:00', '2027-01-08 19:00');
    app(ManageEventTeacherAssignments::class)->assignCustom($laterConflict, [$teacher->id]);
    $data = [
        ...teacherScheduleFormData('Recurring Rehearsal', $teacher, '2027-01-01 18:00', '2027-01-01 19:00'),
        'repeat_frequency' => ScheduleFrequency::Weekly->value,
        'repeat_through' => '2027-01-15',
    ];

    $component = livewire(ListEvents::class)
        ->callAction(CreateAction::class, data: $data)
        ->assertHasActionErrors(['start_time'])
        ->assertSet('mountedActions.0.data.teacher_schedule_conflicts.0', fn (mixed $detail): bool => is_string($detail)
            && str_contains($detail, 'Second Week Conflict')
            && str_contains($detail, 'Jan 8, 2027'));

    expect(Event::query()->where('name', 'Recurring Rehearsal')->count())->toBe(0);

    $component
        ->fillForm(['allow_teacher_schedule_conflicts' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $events = Event::query()
        ->where('name', 'Recurring Rehearsal')
        ->with('teachers')
        ->orderBy('start_time')
        ->get();

    expect($events)->toHaveCount(3)
        ->and($events->map(fn (Event $event): array => $event->teachers->modelKeys())->all())->toBe([
            [$teacher->id],
            [$teacher->id],
            [$teacher->id],
        ]);
});

it('batches conflict discovery across a long recurring submission', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $conflictingEvent = teacherScheduleEvent('One Conflict', '2027-01-15 18:00', '2027-01-15 19:00');
    app(ManageEventTeacherAssignments::class)->assignCustom($conflictingEvent, [$teacher->id]);
    $state = [
        ...teacherScheduleFormData('Daily Rehearsal', $teacher, '2027-01-01 18:00', '2027-01-01 19:00'),
        'start_time' => teacherScheduleUtc('2027-01-01 18:00')->toDateTimeString(),
        'end_time' => teacherScheduleUtc('2027-01-01 19:00')->toDateTimeString(),
        'repeat_frequency' => ScheduleFrequency::Daily->value,
        'repeat_through' => '2027-04-30',
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();

    $conflicts = app(EventScheduleConflictReviewService::class)->conflictsForFormState(
        state: $state,
        includeRecurrences: true,
    );
    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($conflicts)->toHaveCount(1)
        ->and($queryCount)->toBeLessThanOrEqual(10);
});

it('rejects recurring submissions beyond the bounded occurrence limit', function (): void {
    $teacher = User::factory()->isTeacher()->create();

    $component = livewire(ListEvents::class)
        ->callAction(CreateAction::class, data: [
            ...teacherScheduleFormData('Unbounded Daily Rehearsal', $teacher, '2027-01-01 18:00', '2027-01-01 19:00'),
            'repeat_frequency' => ScheduleFrequency::Daily->value,
            'repeat_through' => '2028-12-31',
        ])
        ->assertHasActionErrors(['start_time']);

    expect($component->instance()->getErrorBag()->first('mountedActions.0.data.start_time'))
        ->toContain('366 occurrences')
        ->and(Event::query()->where('name', 'Unbounded Daily Rehearsal')->exists())->toBeFalse();
});

it('supports conflict review from the course event relation manager', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->sync([$teacher->id]);
    $conflictingEvent = teacherScheduleEvent('Busy Rehearsal', '2027-01-15 18:00', '2027-01-15 19:00');
    app(ManageEventTeacherAssignments::class)->assignCustom($conflictingEvent, [$teacher->id]);
    $data = teacherScheduleFormData('Course Overlap', $teacher, '2027-01-15 18:30', '2027-01-15 19:30');
    $data['teacher_assignment_mode'] = EventTeacherAssignmentMode::CourseDefaults->value;
    unset($data['teacher_ids']);

    $component = livewire(EventsRelationManager::class, [
        'ownerRecord' => $course,
        'pageClass' => ViewCourse::class,
    ])->callAction(TestAction::make(CreateAction::class)->table(), data: $data)
        ->assertHasActionErrors(['start_time'])
        ->assertSchemaComponentVisible('allow_teacher_schedule_conflicts');

    expect($course->events()->where('name', 'Course Overlap')->exists())->toBeFalse();

    $component
        ->fillForm(['allow_teacher_schedule_conflicts' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $createdEvent = $course->events()->where('name', 'Course Overlap')->firstOrFail();

    expect($createdEvent->teachers()->pluck('users.id')->all())->toBe([$teacher->id]);
});

it('supports conflict review when editing an event', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $conflictingEvent = teacherScheduleEvent('Busy Rehearsal', '2027-01-15 18:00', '2027-01-15 19:00');
    $assignments = app(ManageEventTeacherAssignments::class);
    $assignments->assignCustom($conflictingEvent, [$teacher->id]);
    $event = teacherScheduleEvent('Editable Event', '2027-01-16 18:00', '2027-01-16 19:00');
    $assignments->assignCustom($event, [$teacher->id]);
    $data = teacherScheduleFormData('Editable Event', $teacher, '2027-01-15 18:30', '2027-01-15 19:30');

    $component = livewire(ViewEvent::class, ['record' => $event->id])
        ->callAction(EditAction::class, data: $data)
        ->assertHasActionErrors(['start_time'])
        ->assertSchemaComponentVisible('allow_teacher_schedule_conflicts');

    expect($event->refresh()->start_time?->timezone(config('app.display_timezone'))->toDateString())->toBe('2027-01-16');

    $component
        ->fillForm(['allow_teacher_schedule_conflicts' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($event->refresh()->start_time?->timezone(config('app.display_timezone'))->toDateString())->toBe('2027-01-15')
        ->and($event->teachers()->pluck('users.id')->all())->toBe([$teacher->id]);
});

it('supports conflict review from the admin calendar create action', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $conflictingEvent = teacherScheduleEvent('Busy Rehearsal', '2027-01-15 18:00', '2027-01-15 19:00');
    app(ManageEventTeacherAssignments::class)->assignCustom($conflictingEvent, [$teacher->id]);

    $component = livewire(CalendarWidget::class)
        ->callAction(CalendarCreateAction::class, data: teacherScheduleFormData(
            'Calendar Overlap',
            $teacher,
            '2027-01-15 18:30',
            '2027-01-15 19:30',
        ))
        ->assertHasActionErrors(['start_time'])
        ->assertSchemaComponentVisible('allow_teacher_schedule_conflicts');

    expect(Event::query()->where('name', 'Calendar Overlap')->exists())->toBeFalse();

    $component
        ->fillForm(['allow_teacher_schedule_conflicts' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Event::query()->where('name', 'Calendar Overlap')->exists())->toBeTrue();
});

it('keeps strict workflows strict and exposes the Shield ability to the intended roles', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $owner = User::factory()->isOwner()->create();
    $superAdministrator = User::factory()->isSuperAdmin()->create();
    $course = Course::factory()->create();
    $course->teachers()->sync([$teacher->id]);
    $conflictingEvent = teacherScheduleEvent('Busy Rehearsal', '2027-01-15 18:00', '2027-01-15 19:00');
    $assignments = app(ManageEventTeacherAssignments::class);
    $assignments->assignCustom($conflictingEvent, [$teacher->id]);
    $courseEvent = teacherScheduleEvent('Course Event', '2027-01-15 18:30', '2027-01-15 19:30', $course);

    expect(fn () => $assignments->initializeCourseEvent($courseEvent))
        ->toThrow(TeacherScheduleConflictException::class, 'already assigned')
        ->and($owner->can('OverrideScheduleConflicts:Event'))->toBeTrue()
        ->and($superAdministrator->can('OverrideScheduleConflicts:Event'))->toBeTrue()
        ->and($teacher->can('OverrideScheduleConflicts:Event'))->toBeFalse()
        ->and(config('filament-shield.resources.manage.'.EventResource::class))
        ->toContain('overrideScheduleConflicts');
});

function teacherScheduleEvent(
    string $name,
    string $startsAt,
    string $endsAt,
    ?Course $course = null,
): Event {
    return Event::factory()->create([
        'name' => $name,
        'course_id' => $course?->id,
        'calendar_id' => teacherScheduleCalendar()->id,
        'start_time' => teacherScheduleUtc($startsAt),
        'end_time' => teacherScheduleUtc($endsAt),
    ]);
}

/** @return array<string, mixed> */
function teacherScheduleFormData(string $name, User $teacher, string $startsAt, string $endsAt): array
{
    return [
        'name' => $name,
        'course_id' => null,
        'teacher_assignment_mode' => EventTeacherAssignmentMode::Custom->value,
        'teacher_ids' => [$teacher->id],
        'start_time' => $startsAt,
        'end_time' => $endsAt,
        'calendar_id' => teacherScheduleCalendar()->id,
    ];
}

function teacherScheduleUtc(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse(
        $dateTime,
        (string) config('app.display_timezone', config('app.timezone')),
    )->timezone((string) config('app.timezone'));
}

function teacherScheduleCalendar(): Calendar
{
    return Calendar::query()->where('slug', Calendar::SLUG_EAC)->firstOrFail();
}
