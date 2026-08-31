<?php

declare(strict_types=1);

use App\Enums\FirstAidType;
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
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Support\Htmlable;
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
        ->mountAction('sendFirstAidNote')
        ->assertSchemaComponentExists(
            'first_aid_type',
            'mountedActionSchema0',
            fn (Select $select): bool => $select->getOptions() === [
                FirstAidType::FirstAid->value => 'FIRST AID',
                FirstAidType::Injury->value => 'INJURY',
                FirstAidType::Medicine->value => 'MEDICINE',
            ],
        )
        ->assertSchemaComponentExists(
            'note',
            'mountedActionSchema0',
            function (Textarea $textarea): bool {
                $helper = $textarea->getChildSchema(Textarea::BELOW_CONTENT_SCHEMA_KEY)?->getComponents()[0] ?? null;

                return $helper instanceof Text
                    && str_contains(
                        (string) $helper->getContent(),
                        'What specific actions were taken during class related to the first aid or injury?',
                    );
            },
        )
        ->unmountAction()
        ->callAction('sendFirstAidNote', data: [
            'to' => ["student:{$student->id}"],
            'occurred_at' => '2026-08-06 18:30:00',
            'event_id' => null,
            'first_aid_type' => FirstAidType::Injury,
            'note' => 'First aid details.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Communication queued');

    $communication = StudentCommunication::query()->sole();

    expect($communication->recipient_emails)->toBe([
        'family@example.com',
        'dancer@example.com',
        'guardian@example.com',
    ])->and($communication->first_aid_type)->toBe(FirstAidType::Injury)
        ->and($communication->occurred_at
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
        ->mountAction('sendStopLightMessage')
        ->assertSchemaComponentExists(
            'stop_light_color',
            'mountedActionSchema0',
            function (Select $select): bool {
                $helper = $select->getChildSchema(Select::BELOW_CONTENT_SCHEMA_KEY)?->getComponents()[0] ?? null;
                $helperText = $helper instanceof Text ? $helper->getContent() : null;
                $helperText = $helperText instanceof Htmlable ? $helperText->toHtml() : (string) $helperText;

                return $select->getOptions() === [
                    StopLightColor::Green->value => 'GREEN',
                    StopLightColor::Yellow->value => 'YELLOW',
                    StopLightColor::Red->value => 'RED',
                ] && str_contains($helperText, 'GREEN Stoplight')
                    && str_contains($helperText, 'YELLOW Stoplight')
                    && str_contains($helperText, 'RED Stoplight');
            },
        )
        ->assertSchemaComponentExists(
            'note',
            'mountedActionSchema0',
            function (Textarea $textarea): bool {
                $helper = $textarea->getChildSchema(Textarea::BELOW_CONTENT_SCHEMA_KEY)?->getComponents()[0] ?? null;

                return $helper instanceof Text
                    && str_contains(
                        (string) $helper->getContent(),
                        'Enter any notes you would like parent(s) to see related to this Stoplight note.',
                    );
            },
        )
        ->unmountAction()
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
    $this->travelTo(CarbonImmutable::parse('2026-08-05 12:00:00', (string) config('app.display_timezone')));
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

    $component = livewire(ViewEvent::class, ['record' => $event->id])
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

    $attendanceAction = $component->instance()->getTable()->getRecordActions()[0];

    expect($attendanceAction)->toBeInstanceOf(Action::class);
    assert($attendanceAction instanceof Action);
    expect($attendanceAction->getName())->toBe('editAttendanceNotes');
});

it('offers event-scoped first aid and stoplight emails to an authorized substitute', function (): void {
    $teacher = User::factory()->isTeacher()->create();
    $family = User::factory()->create(['email' => 'substitute-family@example.com']);
    $student = Student::factory()->for($family)->create();
    $course = Course::factory()->create();
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $student->user_id,
    ]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'substitute_teacher_id' => $teacher->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $this->actingAs($teacher);

    expect($teacher->can('Send:Email'))->toBeTrue()
        ->and(app(App\Support\HandcraftedEmailRecipients::class)->resolve(
            ["student:{$student->id}"],
            $teacher,
            $student,
        ))->toBe(['substitute-family@example.com']);

    $component = livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable()
        ->assertActionHidden(TestAction::make('sendEmail')->table($enrollment))
        ->assertActionVisible(TestAction::make('sendFirstAidNote')->table($enrollment))
        ->assertActionVisible(TestAction::make('sendStopLightMessage')->table($enrollment));

    $component
        ->mountAction(TestAction::make('sendFirstAidNote')->table($enrollment))
        ->assertActionMounted(TestAction::make('sendFirstAidNote')->table($enrollment))
        ->assertActionDataSet([
            'to' => ["student:{$student->id}"],
            'event_id' => $event->id,
        ]);

    expect($component->instance()->getMountedAction()?->isDisabled())->toBeFalse()
        ->and($component->instance()->getMountedAction()?->getActionFunction())->not->toBeNull();

    $component
        ->unmountAction()
        ->mountAction(TestAction::make('sendStopLightMessage')->table($enrollment))
        ->assertActionMounted(TestAction::make('sendStopLightMessage')->table($enrollment))
        ->assertActionDataSet([
            'to' => ["student:{$student->id}"],
            'event_id' => $event->id,
        ]);

    $teacher->roles()->firstOrFail()->revokePermissionTo('Send:Email');
    $this->actingAs($teacher->refresh());

    expect($teacher->can('Send:Email'))->toBeFalse();

    livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable()
        ->assertActionHidden(TestAction::make('sendFirstAidNote')->table($enrollment))
        ->assertActionHidden(TestAction::make('sendStopLightMessage')->table($enrollment));
});

it('records a custom email sent from event attendance in the student history', function (): void {
    Mail::fake();
    $teacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->attach($teacher);
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

    $this->actingAs($teacher);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable()
        ->callAction(TestAction::make('sendEmail')->table($enrollment), data: [
            'to' => ["student:{$student->id}"],
            'subject' => 'Private class follow-up',
            'body' => 'Please review today’s class notes.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Email queued');

    $communication = StudentCommunication::query()->sole();
    $history = app(StudentNotesService::class)
        ->records($student, $teacher)
        ->get("student_communication:{$communication->id}");

    expect($communication->type)->toBe(StudentCommunicationType::CustomEmail)
        ->and($communication->event?->is($event))->toBeTrue()
        ->and($communication->subject)->toBe('Private class follow-up')
        ->and($history['type'])->toBe('custom_email')
        ->and($history['subject'])->toBe('Private class follow-up')
        ->and($history['note'])->toBe('<p>Please review today’s class notes.</p>');
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
    $this->travelTo(CarbonImmutable::parse('2026-08-05 12:00:00', (string) config('app.display_timezone')));
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
