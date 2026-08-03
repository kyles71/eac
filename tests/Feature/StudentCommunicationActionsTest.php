<?php

declare(strict_types=1);

use App\Enums\StopLightColor;
use App\Enums\StudentCommunicationType;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Students\Pages\ListStudents;
use App\Filament\Admin\Resources\Students\Pages\ViewStudent;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\StudentEmail;
use App\Models\User;
use App\Services\StudentCommunicationEventService;
use App\Services\StudentNotesService;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('adds the three contact actions to the student profile and table', function (): void {
    $owner = User::factory()->isOwner()->create();
    $student = Student::factory()->create();

    $this->actingAs($owner);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->assertActionVisible('sendEmail')
        ->assertActionVisible('sendFirstAidNote')
        ->assertActionVisible('sendStopLightMessage')
        ->mountAction('sendFirstAidNote')
        ->assertActionDataSet([
            'to' => ["student:{$student->id}"],
            'event_id' => null,
        ]);

    livewire(ListStudents::class)
        ->loadTable()
        ->assertActionVisible(TestAction::make('sendEmail')->table($student))
        ->assertActionVisible(TestAction::make('sendFirstAidNote')->table($student))
        ->assertActionVisible(TestAction::make('sendStopLightMessage')->table($student));
});

it('uses the shared recipient picker to save and queue a first aid note', function (): void {
    Mail::fake();
    $owner = User::factory()->isOwner()->create();
    $family = User::factory()->create(['email' => 'family@example.com']);
    $student = Student::factory()->for($family)->create();
    StudentEmail::factory()->for($student)->create(['email' => 'dancer@example.com']);
    StudentEmail::factory()->for($student)->create(['email' => 'guardian@example.com']);

    $this->actingAs($owner);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->callAction('sendFirstAidNote', data: [
            'to' => ["student:{$student->id}"],
            'occurred_at' => '2026-08-06 18:30:00',
            'event_id' => null,
            'note' => 'First aid details.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Communication queued');

    $communication = StudentCommunication::query()->sole();

    expect($communication->recipient_emails)->toBe([
        'family@example.com',
        'dancer@example.com',
        'guardian@example.com',
    ])->and($communication->occurred_at
        ->timezone((string) config('app.display_timezone'))
        ->toDateTimeString())->toBe('2026-08-06 18:30:00');
    Mail::assertQueued(ManagedMail::class, 3);
});

it('accepts the enum state produced by the stop light color select', function (): void {
    Mail::fake();
    $owner = User::factory()->isOwner()->create();
    $family = User::factory()->create(['email' => 'family@example.com']);
    $student = Student::factory()->for($family)->create();

    $this->actingAs($owner);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->callAction('sendStopLightMessage', data: [
            'to' => ["student:{$student->id}"],
            'occurred_at' => '2026-08-07 17:45:00',
            'event_id' => null,
            'stop_light_color' => StopLightColor::Yellow,
            'note' => 'A yellow stop light message.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Communication queued');

    $communication = StudentCommunication::query()->sole();

    expect($communication->type)->toBe(StudentCommunicationType::StopLight)
        ->and($communication->stop_light_color)->toBe(StopLightColor::Yellow);
    Mail::assertQueued(ManagedMail::class, 1);
});

it('adds all three contact actions to event attendance with the event locked', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->attach($teacher);
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $eventEndsAt = CarbonImmutable::parse('2026-08-05 19:00:00', (string) config('app.display_timezone'));
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => $eventEndsAt->subHour()->utc(),
        'end_time' => $eventEndsAt->utc(),
    ]);

    $this->actingAs($teacher);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable()
        ->assertActionVisible(TestAction::make('sendEmail')->table($enrollment))
        ->assertActionVisible(TestAction::make('sendFirstAidNote')->table($enrollment))
        ->assertActionVisible(TestAction::make('sendStopLightMessage')->table($enrollment))
        ->mountAction(TestAction::make('sendFirstAidNote')->table($enrollment))
        ->assertSet('mountedActions.0.data.occurred_at', '2026-08-05 19:00')
        ->assertActionDataSet([
            'to' => ["student:{$student->id}"],
            'event_id' => $event->id,
            'occurred_at' => '2026-08-05 23:00',
        ]);
});

it('defaults the communication time to now when no event is selected', function (): void {
    $now = CarbonImmutable::parse('2026-08-08 14:22:00', (string) config('app.display_timezone'));
    $this->travelTo($now);
    $owner = User::factory()->isOwner()->create();
    $student = Student::factory()->create();

    $this->actingAs($owner);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->mountAction('sendFirstAidNote')
        ->assertSet('mountedActions.0.data.occurred_at', '2026-08-08 14:22')
        ->assertActionDataSet([
            'event_id' => null,
            'occurred_at' => '2026-08-08 18:22',
        ]);
});

it('updates the communication time to the selected events end time', function (): void {
    $owner = User::factory()->isOwner()->create();
    $course = Course::factory()->create();
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $eventEndsAt = CarbonImmutable::parse('2026-08-09 19:15:00', (string) config('app.display_timezone'));
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => $eventEndsAt->subHour()->utc(),
        'end_time' => $eventEndsAt->utc(),
    ]);

    $this->actingAs($owner);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->mountAction('sendFirstAidNote')
        ->set('mountedActions.0.data.event_id', (string) $event->id)
        ->assertSet('mountedActions.0.data.occurred_at', '2026-08-09 19:15')
        ->assertActionDataSet([
            'event_id' => $event->id,
            'occurred_at' => '2026-08-09 23:15',
        ]);
});

it('falls back to an events start time when it has no end time', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->attach($teacher);
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $eventStartsAt = CarbonImmutable::parse('2026-08-05 18:00:00', (string) config('app.display_timezone'));
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => $eventStartsAt->utc(),
        'end_time' => null,
    ]);

    $this->actingAs($teacher);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable()
        ->mountAction(TestAction::make('sendStopLightMessage')->table($enrollment))
        ->assertSet('mountedActions.0.data.occurred_at', '2026-08-05 18:00')
        ->assertActionDataSet([
            'event_id' => $event->id,
            'occurred_at' => '2026-08-05 22:00',
        ]);
});

it('lists only relevant authorized events including completed events', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->attach($teacher);
    $student = Student::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $pastEvent = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->subYear(),
        'end_time' => now()->subYear()->addHour(),
    ]);
    Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addYear(),
        'end_time' => now()->addYear()->addHour(),
    ]);
    $unrelatedEvent = Event::factory()->create();

    $this->actingAs($teacher);

    $options = app(StudentCommunicationEventService::class)->options($student, $teacher);

    expect($options)->toHaveKey($pastEvent->id)
        ->and($options)->not->toHaveKey($unrelatedEvent->id);
});

it('shows immutable communication history on the student profile', function (): void {
    $owner = User::factory()->isOwner()->create();
    $student = Student::factory()->create();
    $communication = StudentCommunication::factory()->for($student)->create();

    $this->actingAs($owner);

    $record = app(StudentNotesService::class)
        ->records($student, $owner)
        ->get("student_communication:{$communication->id}");

    livewire(ViewStudent::class, ['record' => $student->id])
        ->loadTable()
        ->assertCanSeeTableRecords([$record['__key']])
        ->assertActionVisible(TestAction::make('viewNote')->table($record))
        ->assertActionHidden(TestAction::make('editStaffNote')->table($record))
        ->assertActionHidden(TestAction::make('deleteStaffNote')->table($record));
});
