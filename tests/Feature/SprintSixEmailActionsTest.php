<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\CompetitionTeams\Pages\ViewCompetitionTeam;
use App\Filament\Admin\Resources\Courses\Pages\ViewCourse;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Resources\Students\Pages\ViewStudent;
use App\Mail\HandcraftedEmail;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use App\Services\EventEmailRecipientsService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    config()->set('mail.mailers.handcrafted.archive_to', '');
});

it('allows a teacher to email an event class or one attendance student and their guardians', function (): void {
    Mail::fake();

    $teacher = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->sync([$teacher->id]);
    $family = User::factory()->create(['email' => 'family@example.com']);
    $student = Student::factory()->for($family)->create();
    StudentEmail::factory()->for($student)->create(['email' => 'dancer@example.com']);
    StudentEmail::factory()->for($student)->create(['email' => 'guardian@example.com']);
    $enrollment = Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $family->id,
    ]);
    $unassignedPurchaser = User::factory()->create(['email' => 'purchaser@example.com']);
    Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $unassignedPurchaser->id,
        'student_id' => null,
    ]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);

    $this->actingAs($teacher);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->mountAction('sendEmail')
        ->assertActionMounted('sendEmail')
        ->assertActionDataSet([
            'to' => [
                "student:{$student->id}",
                'purchaser@example.com',
            ],
        ])
        ->setActionData([
            'to' => [
                "student:{$student->id}",
                'purchaser@example.com',
            ],
            'subject' => 'Event update',
            'body' => 'Please review this class update.',
        ])
        ->callMountedAction()
        ->assertNotified('Email queued');

    Mail::assertQueued(HandcraftedEmail::class, 4);
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('family@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('dancer@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('guardian@example.com'));
    Mail::assertQueued(HandcraftedEmail::class, fn (HandcraftedEmail $mail): bool => $mail->hasTo('purchaser@example.com'));

    livewire(ViewEvent::class, ['record' => $event->id])
        ->loadTable()
        ->mountAction(TestAction::make('sendEmail')->table($enrollment))
        ->assertActionMounted(TestAction::make('sendEmail')->table($enrollment))
        ->assertActionDataSet([
            'to' => ["student:{$student->id}"],
        ]);
});

it('defaults standalone event email recipients to invited students and users', function (): void {
    $event = Event::factory()->create(['course_id' => null]);
    $student = Student::factory()->create();
    $invitedUser = User::factory()->create();
    EventAttendee::factory()->forStudent($student)->create(['event_id' => $event->id]);
    EventAttendee::factory()->forUser($invitedUser)->create(['event_id' => $event->id]);

    $recipients = collect(app(EventEmailRecipientsService::class)->forEvent($event));

    expect($recipients)
        ->toHaveCount(2)
        ->and($recipients->contains(fn (mixed $recipient): bool => $recipient instanceof Student && $recipient->is($student)))
        ->toBeTrue()
        ->and($recipients->contains(fn (mixed $recipient): bool => $recipient instanceof User && $recipient->is($invitedUser)))
        ->toBeTrue();
});

it('hides event email actions from users without the send email permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo([
        'ViewAny:Event',
        'View:Event',
    ]);
    $course = Course::factory()->create();
    $course->teachers()->sync([$user->id]);
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);

    $this->actingAs($user);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->assertActionHidden('sendEmail');
});

it('adds an email action to the student header', function (): void {
    $owner = User::factory()->isOwner()->create();
    $family = User::factory()->create(['email' => 'family@example.com']);
    $student = Student::factory()->for($family)->create();

    $this->actingAs($owner);

    livewire(ViewStudent::class, ['record' => $student->id])
        ->mountAction('sendEmail')
        ->assertActionMounted('sendEmail')
        ->assertActionDataSet([
            'to' => ["student:{$student->id}"],
        ]);
});

it('adds an email action to the course header', function (): void {
    $administrator = User::factory()->isSuperAdmin()->create();
    $family = User::factory()->create(['email' => 'family@example.com']);
    $student = Student::factory()->for($family)->create();
    $course = Course::factory()->create();
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $family->id,
    ]);

    $this->actingAs($administrator);

    livewire(ViewCourse::class, ['record' => $course->id])
        ->mountAction('sendEmail')
        ->assertActionMounted('sendEmail')
        ->assertActionDataSet([
            'to' => ["student:{$student->id}"],
        ]);
});

it('adds an email action to the competition team header', function (): void {
    $administrator = User::factory()->isSuperAdmin()->create();
    $student = Student::factory()->create();
    $team = CompetitionTeam::factory()->create();
    $teamTeacher = User::factory()->isTeacher()->create();
    $team->students()->attach($student);
    $team->staff()->attach($teamTeacher);

    $this->actingAs($administrator);

    livewire(ViewCompetitionTeam::class, ['record' => $team->id])
        ->mountAction('sendEmail')
        ->assertActionMounted('sendEmail')
        ->assertActionDataSet([
            'to' => [
                "student:{$student->id}",
                "teacher:{$teamTeacher->id}",
            ],
        ]);
});
