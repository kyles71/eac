<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Courses\Pages\CourseAttendance;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Services\EventAttendance;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

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

it('toggles attendance from the course attendance matrix', function (): void {
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
        ->call('updateTableColumnState', "attendance_{$event->id}", (string) $enrollment->id, true)
        ->assertHasNoErrors();

    expect(EventAttendee::query()
        ->where('event_id', $event->id)
        ->where('attendee_type', $student->getMorphClass())
        ->where('attendee_id', $student->id)
        ->where('attended', true)
        ->exists())->toBeTrue();
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

it('manages attendance on a single course event', function (): void {
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
        ->call('updateTableColumnState', 'attended', (string) $enrollment->id, true)
        ->call('updateTableColumnState', 'notes', (string) $enrollment->id, 'Arrived late')
        ->assertHasNoErrors();

    $attendance = EventAttendee::query()
        ->where('event_id', $event->id)
        ->where('attendee_type', $student->getMorphClass())
        ->where('attendee_id', $student->id)
        ->firstOrFail();

    expect($attendance->attended)->toBeTrue()
        ->and($attendance->notes)->toBe('Arrived late');
});

it('updates existing student attendance rows instead of duplicating them', function (): void {
    $student = Student::factory()->create();
    $event = Event::factory()->create();
    $attendance = app(EventAttendance::class);

    $attendance->setStudentAttendance($event, $student, true);
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
