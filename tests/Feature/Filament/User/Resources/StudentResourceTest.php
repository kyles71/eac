<?php

declare(strict_types=1);

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

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
});

it('can render the students index page', function () {
    livewire(ListStudents::class)
        ->assertOk();
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
    livewire(ListStudents::class)
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
        'user_id' => auth()->id(),
    ]);
});

it('can delete a student without enrollments', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);

    livewire(ListStudents::class)
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
    $currentCourse = Course::factory()->create([
        'name' => 'Current Ballet',
        'start_time' => now()->subWeek(),
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

    foreach (range(1, 6) as $number) {
        $course = Course::factory()->create([
            'name' => "Past Course {$number}",
            'start_time' => now()->subMonths($number),
        ]);
        Event::factory()->create([
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
        ->assertSee('Current Ballet')
        ->assertSee('Past Course 1')
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
        ->assertActionDataSet(fn (array $data): bool => $data['name'] === $currentEvent->name
            && $data['course_name'] === 'Current Ballet'
            && $data['teacher'] === $currentCourse->teacherDisplayName);
});

it('shows direct student event invitations on the courses and events table', function () {
    $student = Student::factory()->create(['user_id' => auth()->id()]);
    $otherStudent = Student::factory()->create(['user_id' => auth()->id()]);

    $directInvite = Event::factory()->create([
        'name' => 'Private Rehearsal',
        'course_id' => null,
        'start_time' => now()->subDay(),
        'end_time' => now()->subDay()->addHour(),
    ]);
    $otherInvite = Event::factory()->create([
        'name' => 'Other Rehearsal',
        'course_id' => null,
        'start_time' => now()->subDay(),
        'end_time' => now()->subDay()->addHour(),
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
