<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Filament\Admin\Resources\Courses\Pages\CourseAttendance;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Tables\Columns\AttendanceRadioColumn;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\User;
use App\Services\EventAttendanceService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('shows assigned students as course attendance rows and omits open enrollments', function (): void {
    $course = Course::factory()->create();
    $student = Student::factory()->create();
    $assignedEnrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $openEnrollment = Enrollment::factory()->create([
        'course_id' => $course->id,
        'student_id' => null,
    ]);

    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    livewire(CourseAttendance::class, ['record' => $course->id])
        ->loadTable()
        ->assertCanSeeTableRecords([$assignedEnrollment])
        ->assertCanNotSeeTableRecords([$openEnrollment]);
});

it('records each attendance status from the course attendance matrix', function (): void {
    $course = Course::factory()->create();
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    $component = livewire(CourseAttendance::class, ['record' => $course->id])
        ->loadTable()
        ->assertTableColumnExists(
            "attendance_{$event->id}",
            fn (SelectColumn $column): bool => $column->getPlaceholder() === 'Not recorded',
        )
        ->assertTableSelectColumnHasOptions(
            "attendance_{$event->id}",
            collect(AttendanceStatus::cases())
                ->mapWithKeys(fn (AttendanceStatus $status): array => [$status->value => $status->getLabel()])
                ->all(),
            $enrollment,
        );

    foreach (AttendanceStatus::cases() as $status) {
        $component
            ->call(
                'updateTableColumnState',
                "attendance_{$event->id}",
                (string) $enrollment->id,
                $status->value,
            )
            ->assertHasNoErrors();

        expect(EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('attendee_type', $student->getMorphClass())
            ->where('attendee_id', $student->id)
            ->value('status'))->toBe($status);
    }
});

it('sorts course attendance event columns by date and labels them with date and title', function (): void {
    $course = Course::factory()->create();
    $lateEvent = Event::factory()->create([
        'name' => 'Late Class',
        'course_id' => $course->id,
        'start_time' => Carbon::parse('2027-02-15 18:00:00'),
        'end_time' => Carbon::parse('2027-02-15 19:00:00'),
    ]);
    $earlyEvent = Event::factory()->create([
        'name' => 'Early Class',
        'course_id' => $course->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);
    $lateEvent->update(['name' => 'Late Class']);
    $earlyEvent->update(['name' => 'Early Class']);

    $component = livewire(CourseAttendance::class, ['record' => $course->id])
        ->loadTable()
        ->assertTableColumnExists("attendance_{$earlyEvent->id}")
        ->assertTableColumnExists("attendance_{$lateEvent->id}");

    $columnNames = array_keys($component->instance()->getTable()->getColumns());

    expect(array_search("attendance_{$earlyEvent->id}", $columnNames, true))
        ->toBeLessThan(array_search("attendance_{$lateEvent->id}", $columnNames, true))
        ->and($component->instance()->getTable()->getColumn("attendance_{$earlyEvent->id}")->getGroup()?->getLabel())
        ->toBe('01/15 Early Class');
});

it('edits course attendance notes from a notes icon action', function (): void {
    $course = Course::factory()->create();
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    livewire(CourseAttendance::class, ['record' => $course->id])
        ->loadTable()
        ->assertTableColumnStateSet("attendance_notes_{$event->id}", false, $enrollment)
        ->callAction(TestAction::make("editAttendanceNotes_{$event->id}")->table($enrollment), data: [
            'notes' => 'Needs makeup work',
        ])
        ->assertHasNoActionErrors()
        ->assertTableColumnStateSet("attendance_notes_{$event->id}", true, $enrollment);

    expect(EventAttendee::query()
        ->where('event_id', $event->id)
        ->where('attendee_type', $student->getMorphClass())
        ->where('attendee_id', $student->id)
        ->value('notes'))->toBe('Needs makeup work');
});

it('uses radio buttons to manage attendance on a single course event', function (): void {
    $course = Course::factory()->create();
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => Carbon::parse('2027-01-15 18:00:00'),
        'end_time' => Carbon::parse('2027-01-15 19:00:00'),
    ]);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable()
        ->assertTableColumnExists(
            'attendance_status',
            fn (AttendanceRadioColumn $column): bool => ! $column->isDisabled(),
        )
        ->assertSeeHtml('type="radio"')
        ->assertSee('Not recorded')
        ->assertSee('Present')
        ->assertSee('Late')
        ->assertSee('Excused absence')
        ->assertSee('Unexcused absence')
        ->assertSee('Attendance notes are private')
        ->assertActionVisible(TestAction::make('emailAttendance')->table())
        ->callAction(TestAction::make('editAttendanceNotes')->table($enrollment), data: [
            'notes' => 'Arrived late',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Attendance note saved')
        ->call(
            'updateTableColumnState',
            'attendance_status',
            (string) $enrollment->id,
            AttendanceStatus::Late->value,
        )
        ->assertHasNoErrors();

    $attendance = EventAttendee::query()
        ->where('event_id', $event->id)
        ->where('attendee_type', $student->getMorphClass())
        ->where('attendee_id', $student->id)
        ->firstOrFail();

    expect($attendance->status)->toBe(AttendanceStatus::Late)
        ->and($attendance->notes)->toBe('Arrived late');
});

it('adds the event date to the attendance heading in the display timezone', function (): void {
    config()->set('app.display_timezone', 'America/New_York');

    $event = Event::factory()->create([
        'start_time' => Carbon::parse('2027-01-16 01:00:00', 'UTC'),
        'end_time' => Carbon::parse('2027-01-16 02:00:00', 'UTC'),
    ]);

    $component = livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable();

    expect($component->instance()->getTable()->getHeading())
        ->toBe('Attendance — Friday, January 15, 2027');
});

it('resets attendance status while preserving its notes', function (): void {
    $course = Course::factory()->create();
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $attendance = app(EventAttendanceService::class);

    $attendance->setStudentAttendanceStatus($event, $student, AttendanceStatus::Late);
    $attendance->setStudentAttendanceNotes($event, $student, 'Arrived after warmup');

    livewire(CourseAttendance::class, ['record' => $course->id])
        ->loadTable()
        ->call(
            'updateTableColumnState',
            "attendance_{$event->id}",
            (string) $enrollment->id,
            null,
        )
        ->assertHasNoErrors();

    $savedAttendance = EventAttendee::query()
        ->where('event_id', $event->id)
        ->where('attendee_type', $student->getMorphClass())
        ->where('attendee_id', $student->id)
        ->firstOrFail();

    expect($savedAttendance->status)->toBeNull()
        ->and($savedAttendance->notes)->toBe('Arrived after warmup');
});

it('removes an empty attendance-only row after its status is reset', function (): void {
    $course = Course::factory()->create();
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $student = Student::factory()->create();
    $attendance = app(EventAttendanceService::class);

    $attendance->setStudentAttendanceStatus($event, $student, AttendanceStatus::Present);
    $attendance->setStudentAttendanceStatus($event, $student, null);

    expect(EventAttendee::query()
        ->where('event_id', $event->id)
        ->where('attendee_type', $student->getMorphClass())
        ->where('attendee_id', $student->id)
        ->exists())->toBeFalse();
});

it('preserves a standalone event attendee when attendance and notes are cleared', function (): void {
    $event = Event::factory()->create(['course_id' => null]);
    $student = Student::factory()->create();
    $eventAttendee = EventAttendee::factory()->forStudent($student)->create([
        'event_id' => $event->id,
        'status' => AttendanceStatus::Late,
        'notes' => 'Arrived late',
    ]);
    $attendance = app(EventAttendanceService::class);

    $attendance->setStudentAttendanceStatus($event, $student, null);
    $attendance->setStudentAttendanceNotes($event, $student, null);

    expect($eventAttendee->refresh()->status)->toBeNull()
        ->and($eventAttendee->notes)->toBeNull();
});

it('updates existing student attendance rows instead of duplicating them', function (): void {
    $student = Student::factory()->create();
    $event = Event::factory()->create(['course_id' => null]);
    $attendance = app(EventAttendanceService::class);

    $attendance->setStudentAttendanceStatus($event, $student, AttendanceStatus::Present);
    $attendance->setStudentAttendanceNotes($event, $student, 'Present');

    expect(EventAttendee::query()
        ->where('event_id', $event->id)
        ->where('attendee_type', $student->getMorphClass())
        ->where('attendee_id', $student->id)
        ->count())->toBe(1)
        ->and(EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('attendee_type', $student->getMorphClass())
            ->where('attendee_id', $student->id)
            ->value('notes'))->toBe('Present');
});

it('reuses event update authorization when attendance is changed', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $assignedCourse = Course::factory()->create();
    $assignedCourse->teachers()->sync([$teacher->id]);
    $otherCourse = Course::factory()->create();
    $assignedEvent = Event::factory()->create([
        'course_id' => $assignedCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $otherEvent = Event::factory()->create([
        'course_id' => $otherCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $student = Student::factory()->create();
    $attendance = app(EventAttendanceService::class);

    $this->actingAs($teacher);

    expect($attendance
        ->setStudentAttendanceStatus($assignedEvent, $student, AttendanceStatus::Present)
        ->status)->toBe(AttendanceStatus::Present)
        ->and(fn (): EventAttendee => $attendance
            ->setStudentAttendanceStatus($otherEvent, $student, AttendanceStatus::Present))
        ->toThrow(AuthorizationException::class);
});

it('requires event view permission for course and event attendance pages', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'ViewAny:Course',
        'View:Course',
        'ViewAny:Event',
    ]);
    $course = Course::factory()->create();
    $event = Event::factory()->create(['course_id' => $course->id]);

    $this->actingAs($user);

    $this->get(CourseResource::getUrl('attendance', ['record' => $course]))
        ->assertForbidden();
    $this->get(EventResource::getUrl('view', ['record' => $event]))
        ->assertNotFound();

    expect(EventResource::getEloquentQuery()->whereKey($event)->exists())->toBeFalse();
});

it('limits teachers to attendance for courses they teach', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $teacher->givePermissionTo([
        'ViewAny:Course',
        'View:Course',
    ]);
    $assignedCourse = Course::factory()->create();
    $assignedCourse->teachers()->sync([$teacher->id]);
    $otherCourse = Course::factory()->create();
    Event::factory()->create([
        'course_id' => $assignedCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    Event::factory()->create([
        'course_id' => $otherCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);

    $this->actingAs($teacher);

    $this->get(CourseResource::getUrl('view', ['record' => $otherCourse]))
        ->assertOk();
    $this->get(CourseResource::getUrl('attendance', ['record' => $assignedCourse]))
        ->assertOk();
    $this->get(CourseResource::getUrl('attendance', ['record' => $otherCourse]))
        ->assertForbidden();

    expect(Gate::allows('view', $otherCourse))->toBeTrue()
        ->and(Gate::allows('viewAttendance', $assignedCourse))->toBeTrue()
        ->and(Gate::allows('viewAttendance', $otherCourse))->toBeFalse();
});

it('keeps concluded course attendance viewable and read only for teachers', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $teacher->givePermissionTo([
        'ViewAny:Course',
        'View:Course',
    ]);
    $course = Course::factory()->create();
    $course->teachers()->sync([$teacher->id]);
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->subDay()->subHour(),
        'end_time' => now()->subDay(),
    ]);
    $attendance = EventAttendee::factory()->forStudent($student)->create([
        'event_id' => $event->id,
        'status' => null,
        'notes' => 'Historical note',
    ]);

    $this->actingAs($teacher);

    $this->get(CourseResource::getUrl('attendance', ['record' => $course]))
        ->assertOk();
    $this->get(EventResource::getUrl('view', ['record' => $event]))
        ->assertOk();

    expect(Gate::allows('viewAttendance', $course))->toBeTrue()
        ->and(Gate::allows('update', $event))->toBeTrue()
        ->and(Gate::allows('updateAttendance', $event))->toBeFalse();

    $notesAction = TestAction::make("editAttendanceNotes_{$event->id}")->table($enrollment);
    $courseAttendance = livewire(CourseAttendance::class, ['record' => $course->id])
        ->loadTable()
        ->assertCanSeeTableRecords([$enrollment])
        ->assertTableColumnExists(
            "attendance_{$event->id}",
            fn (Column $column): bool => $column->isDisabled(),
            $enrollment,
        )
        ->call(
            'updateTableColumnState',
            "attendance_{$event->id}",
            (string) $enrollment->id,
            AttendanceStatus::Present->value,
        )
        ->assertActionVisible($notesAction)
        ->mountAction($notesAction)
        ->assertActionMounted($notesAction)
        ->assertActionDataSet(['notes' => 'Historical note'])
        ->assertSchemaComponentExists(
            'notes',
            'mountedActionSchema0',
            fn (Textarea $textarea): bool => $textarea->isDisabled(),
        );

    expect($courseAttendance->instance()->getMountedAction()?->getModalSubmitAction())->toBeNull();

    $eventNotesAction = TestAction::make('editAttendanceNotes')->table($enrollment);
    $eventAttendance = livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable()
        ->assertCanSeeTableRecords([$enrollment])
        ->assertTableColumnExists(
            'attendance_status',
            fn (Column $column): bool => $column->isDisabled(),
            $enrollment,
        )
        ->call(
            'updateTableColumnState',
            'attendance_status',
            (string) $enrollment->id,
            AttendanceStatus::Present->value,
        )
        ->assertActionVisible($eventNotesAction)
        ->mountAction($eventNotesAction)
        ->assertActionDataSet(['notes' => 'Historical note'])
        ->assertSchemaComponentExists(
            'notes',
            'mountedActionSchema0',
            fn (Textarea $textarea): bool => $textarea->isDisabled(),
        );

    expect($eventAttendance->instance()->getMountedAction()?->getModalSubmitAction())->toBeNull();

    expect($attendance->refresh()->status)->toBeNull()
        ->and($attendance->notes)->toBe('Historical note')
        ->and(fn (): EventAttendee => app(EventAttendanceService::class)
            ->setStudentAttendanceStatus($event, $student, AttendanceStatus::Present))
        ->toThrow(AuthorizationException::class);
});

it('keeps concluded course attendance read only for owners', function (): void {
    $owner = User::factory()->isOwner()->create();
    $owner->givePermissionTo([
        'ViewAny:Course',
        'View:Course',
    ]);
    $course = Course::factory()->create();
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->subDay()->subHour(),
        'end_time' => now()->subDay(),
    ]);
    EventAttendee::factory()->forStudent($student)->create([
        'event_id' => $event->id,
        'notes' => 'Owner historical note',
    ]);

    $this->actingAs($owner);

    expect(Gate::allows('viewAttendance', $course))->toBeTrue()
        ->and(Gate::allows('update', $event))->toBeTrue()
        ->and(Gate::allows('updateAttendance', $event))->toBeFalse();

    $notesAction = TestAction::make("editAttendanceNotes_{$event->id}")->table($enrollment);
    $courseAttendance = livewire(CourseAttendance::class, ['record' => $course->id])
        ->loadTable()
        ->assertCanSeeTableRecords([$enrollment])
        ->assertTableColumnExists(
            "attendance_{$event->id}",
            fn (Column $column): bool => $column->isDisabled(),
            $enrollment,
        )
        ->assertActionVisible($notesAction)
        ->mountAction($notesAction)
        ->assertActionMounted($notesAction)
        ->assertActionDataSet(['notes' => 'Owner historical note'])
        ->assertSchemaComponentExists(
            'notes',
            'mountedActionSchema0',
            fn (Textarea $textarea): bool => $textarea->isDisabled(),
        );

    expect($courseAttendance->instance()->getMountedAction()?->getModalSubmitAction())->toBeNull();

    expect(fn (): EventAttendee => app(EventAttendanceService::class)
        ->setStudentAttendanceStatus($event, $student, AttendanceStatus::Present))
        ->toThrow(AuthorizationException::class);
});

it('renders attendance and notes as read only without event update permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'ViewAny:Course',
        'View:Course',
        'ViewAny:Event',
        'View:Event',
    ]);
    $course = Course::factory()->create();
    $course->teachers()->sync([$user->id]);
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $attendance = EventAttendee::factory()->forStudent($student)->create([
        'event_id' => $event->id,
        'status' => null,
        'notes' => 'Read-only note',
    ]);

    $this->actingAs($user);

    $notesAction = TestAction::make("editAttendanceNotes_{$event->id}")->table($enrollment);
    $courseAttendance = livewire(CourseAttendance::class, ['record' => $course->id])
        ->loadTable()
        ->assertTableColumnExists(
            "attendance_{$event->id}",
            fn (Column $column): bool => $column->isDisabled(),
            $enrollment,
        )
        ->call(
            'updateTableColumnState',
            "attendance_{$event->id}",
            (string) $enrollment->id,
            AttendanceStatus::Present->value,
        )
        ->assertActionVisible($notesAction)
        ->mountAction($notesAction)
        ->assertActionMounted($notesAction)
        ->assertActionDataSet(['notes' => 'Read-only note'])
        ->assertSchemaComponentExists(
            'notes',
            'mountedActionSchema0',
            fn (Textarea $textarea): bool => $textarea->isDisabled(),
        );

    expect($courseAttendance->instance()->getMountedAction()?->getModalSubmitAction())->toBeNull();

    $eventNotesAction = TestAction::make('editAttendanceNotes')->table($enrollment);
    $eventAttendance = livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable()
        ->assertTableColumnExists(
            'attendance_status',
            fn (Column $column): bool => $column->isDisabled(),
            $enrollment,
        )
        ->call(
            'updateTableColumnState',
            'attendance_status',
            (string) $enrollment->id,
            AttendanceStatus::Present->value,
        )
        ->assertActionVisible($eventNotesAction)
        ->mountAction($eventNotesAction)
        ->assertActionDataSet(['notes' => 'Read-only note'])
        ->assertSchemaComponentExists(
            'notes',
            'mountedActionSchema0',
            fn (Textarea $textarea): bool => $textarea->isDisabled(),
        );

    expect($eventAttendance->instance()->getMountedAction()?->getModalSubmitAction())->toBeNull();

    expect($attendance->refresh()->status)->toBeNull()
        ->and($attendance->notes)->toBe('Read-only note');
});
