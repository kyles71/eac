<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\FormTypes;
use App\Enums\StopLightColor;
use App\Enums\StudentCommunicationType;
use App\Enums\StudentNoteType;
use App\Filament\Admin\Resources\Students\Pages\ViewStudent;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\StaffNote;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Services\StudentNotesService;
use App\Services\StudentProfileService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('shows teachers the completed medical waiver details and media release consent', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->sync([$teacher->id]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $form = Form::factory()->create([
        'form_type' => FormTypes::StudentWaiver,
        'valid_until' => now()->addMonth(),
    ]);
    $waiver = StudentWaiver::factory()->create([
        'allergies' => 'Peanuts',
        'medical_conditions' => 'Asthma',
        'past_injuries' => 'Sprained ankle',
        'medications' => 'Rescue inhaler',
        'behavioral_notes' => 'Needs quiet instructions',
        'medical_release_consent' => true,
        'media_release_consent' => false,
    ]);
    FormUser::factory()->forStudent($student)->create([
        'form_id' => $form->id,
        'user_id' => $student->user_id,
        'responseable_type' => $waiver->getMorphClass(),
        'responseable_id' => $waiver->id,
    ]);

    $this->actingAs($teacher);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->assertSee('On File')
        ->assertSee('Peanuts')
        ->assertSee('Asthma')
        ->assertSee('Sprained ankle')
        ->assertSee('Rescue inhaler')
        ->assertSee('Needs quiet instructions')
        ->assertSee('Agreed')
        ->assertSee('Declined');
});

it('totals occurred non-cancelled event attendance by course', function (): void {
    $owner = User::factory()->isOwner()->create();
    $course = Course::factory()->create(['name' => 'Attendance Course']);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);

    foreach (AttendanceStatus::cases() as $status) {
        $event = Event::factory()->create([
            'course_id' => $course->id,
            'start_time' => now()->subDays(5),
            'end_time' => now()->subDays(5)->addHour(),
        ]);
        EventAttendee::factory()->forStudent($student)->create([
            'event_id' => $event->id,
            'status' => $status,
            'notes' => $status === AttendanceStatus::Late ? 'Traffic delay' : null,
        ]);
    }

    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->subDay(),
        'end_time' => now()->subDay()->addHour(),
    ]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->subDay(),
        'end_time' => now()->subDay()->addHour(),
        'cancelled_at' => now()->subDays(2),
    ]);

    $profile = app(StudentProfileService::class);

    $this->actingAs($owner);

    $attendanceNotes = app(StudentNotesService::class)
        ->records($student, $owner)
        ->where('type', StudentNoteType::Attendance->value);

    expect($profile->attendanceTotals($student))->toBe([[
        'course' => 'Attendance Course',
        'present' => 1,
        'late' => 1,
        'excused_absence' => 1,
        'unexcused_absence' => 1,
        'not_recorded' => 1,
        'total_events' => 5,
    ]])
        ->and($attendanceNotes)->toHaveCount(1)
        ->and($attendanceNotes->first()['note'])->toBe('Traffic delay');

    livewire(ViewStudent::class, ['record' => $student->id])
        ->loadTable()
        ->assertSee('Attendance Course')
        ->assertSee('Traffic delay');
});

it('combines attendance, staff, first aid, and stop light notes into one filterable table', function (): void {
    $owner = User::factory()->isOwner()->create();
    $student = Student::factory()->create();
    $event = Event::factory()->create([
        'name' => 'Aggregate Event',
        'start_time' => now()->subDay(),
        'end_time' => now()->subDay()->addHour(),
    ]);
    $attendance = EventAttendee::factory()->forStudent($student)->create([
        'event_id' => $event->id,
        'notes' => 'Attendance context',
    ]);
    $staffNote = StaffNote::factory()->create([
        'student_id' => $student->id,
        'author_id' => $owner->id,
        'note' => 'Staff context',
    ]);
    $firstAid = StudentCommunication::factory()->for($student)->create([
        'author_id' => $owner->id,
        'type' => StudentCommunicationType::FirstAid,
        'note' => 'First aid context',
    ]);
    $stopLight = StudentCommunication::factory()->for($student)->create([
        'author_id' => $owner->id,
        'type' => StudentCommunicationType::StopLight,
        'first_aid_type' => null,
        'stop_light_color' => StopLightColor::Yellow,
        'note' => 'Stop light context',
    ]);

    $this->actingAs($owner);

    $records = app(StudentNotesService::class)->records($student, $owner);
    $attendanceRecord = $records->get("attendance:{$attendance->id}");
    $staffRecord = $records->get("staff_note:{$staffNote->id}");
    $firstAidRecord = $records->get("student_communication:{$firstAid->id}");
    $stopLightRecord = $records->get("student_communication:{$stopLight->id}");

    expect($records)->toHaveCount(4)
        ->and($records->pluck('type')->all())->toContain(
            StudentNoteType::Attendance->value,
            StudentNoteType::Staff->value,
            StudentNoteType::FirstAid->value,
            StudentNoteType::StopLight->value,
        )
        ->and($firstAidRecord['communication_type'])->toBe('FIRST AID')
        ->and($stopLightRecord['communication_type'])->toBe('YELLOW');

    livewire(ViewStudent::class, ['record' => $student->id])
        ->loadTable()
        ->assertTableColumnExists(
            'communication_type',
            fn (TextColumn $column): bool => $column->getLabel() === 'Type',
        )
        ->assertCanSeeTableRecords([
            $attendanceRecord['__key'],
            $staffRecord['__key'],
            $firstAidRecord['__key'],
            $stopLightRecord['__key'],
        ])
        ->filterTable('type', StudentNoteType::Staff->value)
        ->assertCanSeeTableRecords([$staffRecord['__key']])
        ->assertCanNotSeeTableRecords([
            $attendanceRecord['__key'],
            $firstAidRecord['__key'],
            $stopLightRecord['__key'],
        ]);
});

it('displays communication timestamps on the saved local date in the table and modal', function (): void {
    config()->set('app.display_timezone', 'America/New_York');
    $owner = User::factory()->isOwner()->create();
    $student = Student::factory()->create();
    $occurredAt = CarbonImmutable::parse('2026-08-07 00:30:00', 'America/New_York');
    $communication = StudentCommunication::factory()->for($student)->create([
        'author_id' => $owner->id,
        'type' => StudentCommunicationType::FirstAid,
        'occurred_at' => $occurredAt->utc(),
        'note' => 'Late evening first aid note',
    ]);

    $this->actingAs($owner);

    $record = app(StudentNotesService::class)
        ->records($student, $owner)
        ->get("student_communication:{$communication->id}");

    expect($record['date'])->toBe('Aug 7, 2026 12:30 AM');

    livewire(ViewStudent::class, ['record' => $student->id])
        ->loadTable()
        ->assertSee('Aug 7, 2026 12:30 AM')
        ->mountAction(TestAction::make('viewNote')->table($record))
        ->assertSee('Aug 7, 2026 12:30 AM');
});
