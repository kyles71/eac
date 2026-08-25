<?php

declare(strict_types=1);

use App\Filament\User\Resources\FormUsers\FormUserResource;
use App\Filament\User\Resources\Students\Pages\CreateStudent;
use App\Filament\User\Resources\Students\Pages\ListStudents;
use App\Filament\User\Resources\Students\Pages\ViewStudent;
use App\Filament\User\Resources\Students\StudentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
});

it('can render the students index page', function () {
    $component = livewire(ListStudents::class)
        ->assertOk()
        ->assertSee('Add Student');
    $createAction = $component->instance()->getAction('create');

    expect($createAction)->toBeInstanceOf(CreateAction::class)
        ->and($createAction?->getLabel())->toBe('Add Student')
        ->and($createAction?->canCreateAnother())->toBeFalse();

    livewire(CreateStudent::class)
        ->assertOk()
        ->assertDontSee('Create & create another');
});

it('keeps user panel resources out of global search', function (): void {
    expect(StudentResource::canGloballySearch())->toBeFalse()
        ->and(FormUserResource::canGloballySearch())->toBeFalse();
});

it('only lists students on the authenticated account', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $otherStudent = Student::factory()->create(['user_id' => User::factory()]);

    livewire(ListStudents::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$student])
        ->assertCanNotSeeTableRecords([$otherStudent]);
});

it('opens student rows on an account-scoped detail page', function () {
    $student = Student::factory()->create([
        'user_id' => auth()->id(),
        'first_name' => 'Avery',
        'last_name' => 'Dancer',
        'birthdate' => now()->subYears(10)->toDateString(),
    ]);
    $otherStudent = Student::factory()->create();

    livewire(ListStudents::class)
        ->loadTable()
        ->assertSee(StudentResource::getUrl('view', ['record' => $student]), false);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->assertOk()
        ->assertSee('Avery')
        ->assertSee('Dancer')
        ->assertSee('10')
        ->assertSee('Save Student Details');

    $this->get(StudentResource::getUrl('view', ['record' => $otherStudent]))
        ->assertNotFound();
});

it('updates only nickname and up to three additional student emails inline', function () {
    $student = Student::factory()->create([
        'user_id' => auth()->id(),
        'first_name' => 'Avery',
        'last_name' => 'Dancer',
        'nickname' => null,
    ]);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->fillForm([
            'nickname' => 'Ave',
            'additional_emails' => [
                [
                    'email' => 'MOTHER@example.com',
                    'relationship_option' => 'Mother',
                    'relationship' => 'Mother',
                ],
                [
                    'email' => 'coach@example.com',
                    'relationship_option' => 'Other',
                    'relationship' => 'Coach',
                ],
            ],
        ], 'contactForm')
        ->call('saveContactDetails')
        ->assertHasNoFormErrors([], 'contactForm')
        ->assertNotified('Student details saved');

    expect($student->refresh()->nickname)->toBe('Ave')
        ->and($student->first_name)->toBe('Avery')
        ->and($student->last_name)->toBe('Dancer')
        ->and($student->additionalEmails()->pluck('relationship', 'email')->all())->toMatchArray([
            'mother@example.com' => 'Mother',
            'coach@example.com' => 'Coach',
        ]);

    $coachEmail = $student->additionalEmails()->where('email', 'coach@example.com')->firstOrFail();

    livewire(ViewStudent::class, ['record' => $student->id])
        ->fillForm([
            'nickname' => 'Avery',
            'additional_emails' => [
                [
                    'id' => $coachEmail->id,
                    'email' => 'guardian@example.com',
                    'relationship_option' => 'Other',
                    'relationship' => 'Guardian',
                ],
            ],
        ], 'contactForm')
        ->call('saveContactDetails')
        ->assertHasNoFormErrors([], 'contactForm');

    expect($student->refresh()->nickname)->toBe('Avery')
        ->and($student->additionalEmails)->toHaveCount(1)
        ->and($student->additionalEmails->first()->email)->toBe('guardian@example.com')
        ->and($student->additionalEmails->first()->relationship)->toBe('Guardian');
});

it('validates the additional student email limit and duplicates', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $email = fn (int $number): array => [
        'email' => "email{$number}@example.com",
        'relationship_option' => 'Dancer',
        'relationship' => 'Dancer',
    ];

    livewire(ViewStudent::class, ['record' => $student->id])
        ->fillForm([
            'additional_emails' => [$email(1), $email(2), $email(3), $email(4)],
        ], 'contactForm')
        ->call('saveContactDetails')
        ->assertHasFormErrors(['additional_emails'], 'contactForm');

    livewire(ViewStudent::class, ['record' => $student->id])
        ->fillForm([
            'additional_emails' => [
                $email(1),
                [
                    ...$email(1),
                    'email' => 'EMAIL1@example.com',
                ],
            ],
        ], 'contactForm')
        ->call('saveContactDetails')
        ->assertHasFormErrors(['additional_emails'], 'contactForm');

    livewire(ViewStudent::class, ['record' => $student->id])
        ->fillForm([
            'additional_emails' => [[
                'email' => 'other@example.com',
                'relationship_option' => 'Other',
                'relationship' => null,
            ]],
        ], 'contactForm')
        ->call('saveContactDetails')
        ->assertHasFormErrors(['additional_emails.0.relationship'], 'contactForm');

    expect(StudentEmail::query()->where('student_id', $student->id)->count())->toBe(0);
});

it('can create a student for the authenticated account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(ListStudents::class)
        ->assertActionVisible(CreateAction::class)
        ->callAction(CreateAction::class, data: [
            'first_name' => 'Avery',
            'last_name' => 'Dancer',
            'nickname' => 'Ave',
            'birthdate' => '2015-04-12',
        ])
        ->assertNotified();

    assertDatabaseHas(Student::class, [
        'first_name' => 'Avery',
        'last_name' => 'Dancer',
        'nickname' => 'Ave',
        'birthdate' => '2015-04-12 00:00:00',
        'user_id' => $user->id,
    ]);
});

it('can delete a student without enrollments', function () {
    $user = User::factory()->create();
    $student = Student::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(ListStudents::class)
        ->assertActionVisible(TestAction::make(DeleteAction::class)->table($student))
        ->callAction(TestAction::make(DeleteAction::class)->table($student))
        ->assertNotified();

    assertDatabaseMissing(Student::class, [
        'id' => $student->id,
    ]);
});

it('hides delete for students with enrollments', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);

    Enrollment::factory()
        ->withStudent($student)
        ->create([
            'user_id' => auth()->id(),
            'student_id' => $student->id,
        ]);

    livewire(ListStudents::class)
        ->assertActionHidden(TestAction::make(DeleteAction::class)->table($student));
});

it('shows current classes and progressively loads past enrollment history', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $currentTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Pearl',
        'last_name' => 'Primus',
        'staff_bio' => 'Pearl teaches the current class.',
    ]);
    $pastTeacher = User::factory()->isTeacher()->create([
        'first_name' => 'Alvin',
        'last_name' => 'Ailey',
        'staff_bio' => 'Alvin taught this past class.',
    ]);
    $currentCourse = Course::factory()->create([
        'name' => 'Current Ballet',
    ]);
    $currentCourse->teachers()->sync([$currentTeacher->id]);
    Event::factory()->create([
        'course_id' => $currentCourse->id,
        'start_time' => now()->subWeek(),
        'end_time' => now()->subWeek()->addHour(),
    ]);
    $currentEvent = Event::factory()->create([
        'course_id' => $currentCourse->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $currentCourse->id,
        'user_id' => auth()->id(),
    ]);

    $pastMeetingWithoutEnd = now()->subDay()->startOfMinute();

    $pastEvents = [];

    foreach (range(1, 6) as $number) {
        $course = Course::factory()->create([
            'name' => "Past Course {$number}",
        ]);
        if ($number === 1) {
            $course->teachers()->sync([$pastTeacher->id]);
        }
        $pastEvents[] = Event::factory()->create([
            'course_id' => $course->id,
            'start_time' => $number === 1 ? $pastMeetingWithoutEnd : now()->subDays($number),
            'end_time' => $number === 1 ? null : now()->subDays($number)->addHour(),
        ]);
        Enrollment::factory()->withStudent($student)->create([
            'course_id' => $course->id,
            'user_id' => auth()->id(),
        ]);
    }

    livewire(ViewStudent::class, ['record' => $student->id])
        ->loadTable()
        ->assertCanSeeTableRecords([$currentEvent])
        ->assertCanNotSeeTableRecords($pastEvents)
        ->assertSee('Current Ballet')
        ->assertSee('Pearl Primus')
        ->assertSee('Pearl teaches the current class.')
        ->assertSee('Past Course 1')
        ->assertSee('Alvin Ailey')
        ->assertSee('Alvin taught this past class.')
        ->assertDontSee('&lt;!--[if', false)
        ->assertSee('Past Course 5')
        ->assertSee($pastMeetingWithoutEnd
            ->timezone((string) config('app.display_timezone', config('app.timezone')))
            ->format('M j, Y g:i A'))
        ->assertSee('Show additional')
        ->call('loadMoreHistory')
        ->assertSet('automaticHistoryLoading', true)
        ->assertSet('historyLimit', 10);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->loadTable()
        ->mountAction(TestAction::make('viewStudentEventDetails')->table($currentEvent))
        ->assertActionMounted(TestAction::make('viewStudentEventDetails')->table($currentEvent))
        ->assertSchemaComponentExists('teacher', 'mountedActionSchema0', function (TextEntry $entry): bool {
            $state = $entry->formatState($entry->getState());

            return $state instanceof Htmlable
                && str_contains($state->toHtml(), 'Pearl Primus')
                && str_contains($state->toHtml(), 'Pearl teaches the current class.');
        })
        ->assertActionDataSet(fn (array $data): bool => $data['name'] === $currentEvent->name
            && $data['course_name'] === 'Current Ballet'
            && $data['teacher'] === 'Pearl Primus');
});

it('shows direct student event invitations on the courses and events table', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $otherStudent = Student::factory()->create(['user_id' => auth()->id()]);

    $directInvite = Event::factory()->create([
        'name' => 'Private Rehearsal',
        'course_id' => null,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $otherInvite = Event::factory()->create([
        'name' => 'Other Rehearsal',
        'course_id' => null,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);

    EventAttendee::factory()->forStudent($student)->create(['event_id' => $directInvite->id]);
    EventAttendee::factory()->forStudent($otherStudent)->create(['event_id' => $otherInvite->id]);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->loadTable()
        ->assertCanSeeTableRecords([$directInvite])
        ->assertCanNotSeeTableRecords([$otherInvite])
        ->assertSee('Private Rehearsal')
        ->assertDontSee('Other Rehearsal')
        ->mountAction(TestAction::make('viewStudentEventDetails')->table($directInvite))
        ->assertActionMounted(TestAction::make('viewStudentEventDetails')->table($directInvite))
        ->assertActionDataSet(fn (array $data): bool => $data['name'] === 'Private Rehearsal'
            && $data['course_name'] === null);
});

it('groups recurring course events while keeping schedule exceptions separate', function (): void {
    $displayTimezone = (string) config('app.display_timezone', config('app.timezone'));
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->create(['name' => 'Ballet Company']);
    $firstMeeting = now($displayTimezone)
        ->addWeek()
        ->next('Wednesday')
        ->setTime(18, 0)
        ->utc();
    $recurringEvents = collect(range(0, 2))
        ->map(fn (int $week): Event => Event::factory()->create([
            'name' => 'Ballet Company',
            'course_id' => $course->id,
            'start_time' => $firstMeeting->copy()->addWeeks($week),
            'end_time' => $firstMeeting->copy()->addWeeks($week)->addHour(),
        ]));
    $rescheduledEvent = Event::factory()->create([
        'name' => 'Ballet Company',
        'course_id' => $course->id,
        'start_time' => $firstMeeting->copy()->addDay()->addHours(2),
        'end_time' => $firstMeeting->copy()->addDay()->addHours(3),
    ]);
    $addedEvent = Event::factory()->create([
        'name' => 'Ballet Company',
        'course_id' => $course->id,
        'start_time' => $firstMeeting->copy()->addWeek()->addHours(3),
        'end_time' => $firstMeeting->copy()->addWeek()->addHours(4),
    ]);

    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
    ]);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->loadTable()
        ->assertTableColumnExists(
            'event_summary',
            fn (TextColumn $column): bool => $column->getLabel() === 'Event',
        )
        ->assertTableColumnExists(
            'start_time',
            fn (TextColumn $column): bool => $column->getLabel() === 'Next Meeting Time',
        )
        ->assertCanSeeTableRecords([$recurringEvents->first(), $rescheduledEvent, $addedEvent])
        ->assertCanNotSeeTableRecords($recurringEvents->skip(1))
        ->assertSee('Ballet Company Class - 2 more Wednesdays')
        ->assertSee($firstMeeting->timezone($displayTimezone)->format('M j, Y g:i A'))
        ->assertSee('Course History')
        ->assertDontSee('Enrollment History');
});

it('does not expose private attendance notes on the family student profile', function (): void {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $course = Course::factory()->create();
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => auth()->id(),
    ]);
    EventAttendee::factory()->forStudent($student)->create([
        'event_id' => $event->id,
        'notes' => 'Private staff-only attendance context',
    ]);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->loadTable()
        ->assertSee($event->name)
        ->assertDontSee('Private staff-only attendance context');
});
