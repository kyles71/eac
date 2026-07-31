<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\FormTypes;
use App\Filament\Admin\Resources\Students\Pages\ViewStudent;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Form;
use App\Models\FormUser;
use App\Models\Student;
use App\Models\StudentWaiver;
use App\Models\User;
use App\Services\StudentProfileService;
use Filament\Facades\Filament;

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

    expect($profile->attendanceTotals($student))->toBe([[
        'course' => 'Attendance Course',
        'present' => 1,
        'late' => 1,
        'excused_absence' => 1,
        'unexcused_absence' => 1,
        'not_recorded' => 1,
        'total_events' => 5,
    ]])
        ->and($profile->attendanceNotes($student))->toHaveCount(1)
        ->and($profile->attendanceNotes($student)[0]['note'])->toBe('Traffic delay');

    livewire(ViewStudent::class, ['record' => $student->id])
        ->assertSee('Attendance Course')
        ->assertSee('Traffic delay');
});
