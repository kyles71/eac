<?php

declare(strict_types=1);

use App\Actions\Events\CancelEvent;
use App\Filament\Actions\CancelEventAction;
use App\Filament\Admin\Resources\Courses\Pages\ViewCourse;
use App\Filament\Admin\Resources\Courses\RelationManagers\EventsRelationManager;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\StudentEmail;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;

use function Pest\Livewire\livewire;

it('exposes the cancellation permission in the Shield event permissions', function (): void {
    Filament::setCurrentPanel('admin');

    expect(FilamentShield::getResourcePolicyActionsWithPermissions(EventResource::class))
        ->toHaveKey('cancel', 'Cancel:Event');
});

it('cancels an event without sending email and records the audit details', function (): void {
    Mail::fake();
    $event = Event::factory()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class)
        ->and(app(CancelEvent::class)->handle($event, $actor, '  Studio closed   for weather. ', false))->toBe(0);

    expect($event->refresh()->isCancelled())->toBeTrue()
        ->and($event->cancellation_reason)->toBe('Studio closed for weather.')
        ->and($event->cancelled_by_user_id)->toBe($actor->id)
        ->and($event->cancelled_at)->not->toBeNull();

    Mail::assertNothingQueued();
});

it('requires the cancellation permission at the domain boundary', function (): void {
    $event = Event::factory()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $unauthorizedUser = User::factory()->create();

    app(CancelEvent::class)->handle($event, $unauthorizedUser, 'No permission.', false);
})->throws(AuthorizationException::class);

it('does not allow an event to be cancelled twice', function (): void {
    $event = Event::factory()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    app(CancelEvent::class)->handle($event, $actor, 'First cancellation.', false);
    app(CancelEvent::class)->handle($event, $actor, 'Second cancellation.', true);
})->throws(DomainException::class, 'This event has already been cancelled.');

it('defines the cancellation window from the end time or start time when no end time exists', function (): void {
    $dateTime = Carbon::parse('2026-06-19 12:00:00');

    expect((new Event([
        'start_time' => $dateTime->copy()->subHour(),
        'end_time' => $dateTime->copy()->addMinute(),
    ]))->canBeCancelledAt($dateTime))->toBeTrue()
        ->and((new Event([
            'start_time' => $dateTime->copy()->subHours(2),
            'end_time' => $dateTime,
        ]))->canBeCancelledAt($dateTime))->toBeFalse()
        ->and((new Event([
            'start_time' => $dateTime->copy()->addMinute(),
            'end_time' => null,
        ]))->canBeCancelledAt($dateTime))->toBeTrue()
        ->and((new Event([
            'start_time' => $dateTime,
            'end_time' => null,
        ]))->canBeCancelledAt($dateTime))->toBeFalse()
        ->and((new Event([
            'start_time' => null,
            'end_time' => null,
        ]))->canBeCancelledAt($dateTime))->toBeFalse();
});

it('rejects cancellation of a completed event at the locked domain boundary', function (): void {
    Mail::fake();
    $event = Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class)
        ->and(fn () => app(CancelEvent::class)->handle(
            $event,
            $actor,
            'This must not be accepted.',
            true,
        ))->toThrow(DomainException::class, 'Events with an end time can only be cancelled before they end.');

    expect($event->refresh()->isCancelled())->toBeFalse()
        ->and($event->cancellation_reason)->toBeNull()
        ->and($event->cancelled_by_user_id)->toBeNull();
    Mail::assertNothingQueued();
});

it('queues individualized handcrafted emails to all associated attendee addresses', function (): void {
    Mail::fake();
    $actor = auth()->user();
    $course = Course::factory()->create(['name' => 'Ballet 2']);
    $calendar = Calendar::factory()->create(['name' => 'EAC Calendar']);
    $event = Event::factory()->create([
        'name' => 'Ballet 2 Class',
        'course_id' => $course->id,
        'calendar_id' => $calendar->id,
        'start_time' => now()->addWeek(),
        'end_time' => now()->addWeek()->addHour(),
    ]);
    $event->teachers()->firstOrFail()->update(['email' => 'teacher@example.com']);

    $account = User::factory()->create(['email' => 'guardian@example.com']);
    $student = Student::factory()->create(['user_id' => $account->id]);
    StudentEmail::factory()->create([
        'student_id' => $student->id,
        'email' => 'dancer@example.com',
    ]);
    Enrollment::factory()->withStudent($student)->create([
        'course_id' => $course->id,
        'user_id' => $account->id,
    ]);
    EventAttendee::factory()->forStudent($student)->create(['event_id' => $event->id]);

    $directUser = User::factory()->create(['email' => 'direct@example.com']);
    EventAttendee::factory()->forUser($directUser)->create(['event_id' => $event->id]);

    $directStudentAccount = User::factory()->create(['email' => 'second-guardian@example.com']);
    $directStudent = Student::factory()->create(['user_id' => $directStudentAccount->id]);
    StudentEmail::factory()->create([
        'student_id' => $directStudent->id,
        'email' => 'second-dancer@example.com',
    ]);
    EventAttendee::factory()->forStudent($directStudent)->create(['event_id' => $event->id]);

    $excludedUser = User::factory()->create(['email' => 'excluded@example.com']);
    EventAttendee::factory()->forUser($excludedUser)->create(['event_id' => $event->id]);
    $event->excludedUsers()->attach($excludedUser);

    expect($actor)->toBeInstanceOf(User::class)
        ->and(app(CancelEvent::class)->handle(
            $event,
            $actor,
            'Weather <unsafe>',
            true,
        ))->toBe(6);

    Mail::assertQueued(ManagedMail::class, 6);

    foreach ([
        'guardian@example.com',
        'dancer@example.com',
        'direct@example.com',
        'second-guardian@example.com',
        'second-dancer@example.com',
        'teacher@example.com',
    ] as $recipient) {
        Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'event-cancellation'
            && $mail->hasTo($recipient)
            && $mail->usesMailer('handcrafted'));
    }

    Mail::assertNotQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->hasTo('excluded@example.com'));
    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $rendered->subject === 'Cancelled: Ballet 2 Class'
            && str_contains($rendered->html, 'Weather &lt;unsafe&gt;')
            && str_contains($rendered->html, 'EAC Calendar');
    });
});

it('exposes the cancellation action on the events resource', function (): void {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $event = Event::factory()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);

    livewire(ListEvents::class)
        ->callAction(
            TestAction::make('cancelEvent')
                ->table($event)
                ->arguments(['send_email' => false]),
            ['reason' => 'Cancelled from the events resource.'],
        )
        ->assertNotified('Event cancelled without sending email');

    expect($event->refresh()->isCancelled())->toBeTrue();
    Mail::assertNothingQueued();
});

it('requires a reason and presents the three cancellation choices', function (): void {
    Filament::setCurrentPanel('admin');
    $event = Event::factory()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);

    livewire(ListEvents::class)
        ->callAction(
            TestAction::make('cancelEvent')->table($event),
            [],
        )
        ->assertHasActionErrors(['reason' => 'required']);

    $component = livewire(ListEvents::class);
    $action = CancelEventAction::make()
        ->livewire($component->instance())
        ->record($event);

    expect($action->getModalSubmitActionLabel())->toBe('Cancel and Send Email')
        ->and($action->getExtraModalFooterActions())
        ->toHaveCount(1)
        ->and($action->getExtraModalFooterActions()['cancelWithoutEmail']->getLabel())
        ->toBe('Cancel Without Sending Email')
        ->and($action->getModalCancelActionLabel())->toBe('Cancel / Close');

    expect($event->refresh()->isCancelled())->toBeFalse();
});

it('exposes the same cancellation action on a course events table', function (): void {
    Filament::setCurrentPanel('admin');
    $course = Course::factory()->create();
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);

    $component = livewire(EventsRelationManager::class, [
        'ownerRecord' => $course,
        'pageClass' => ViewCourse::class,
    ])->loadTable();

    expect($component->instance()->getTable()->getRecordUrl($event))
        ->toBe(EventResource::getUrl('view', ['record' => $event]));

    $component->assertActionExists(TestAction::make(EditAction::class)->table($event));

    $component->callAction(
        TestAction::make('cancelEvent')
            ->table($event)
            ->arguments(['send_email' => false]),
        ['reason' => 'Cancelled from course details.'],
    )
        ->assertNotified('Event cancelled without sending email');

    expect($event->refresh()->isCancelled())->toBeTrue();
});

it('hides cancellation for completed events on event and course tables', function (): void {
    Filament::setCurrentPanel('admin');
    $course = Course::factory()->create();
    $event = Event::factory()->create([
        'course_id' => $course->id,
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);

    livewire(ListEvents::class)
        ->assertActionHidden(TestAction::make('cancelEvent')->table($event));

    livewire(EventsRelationManager::class, [
        'ownerRecord' => $course,
        'pageClass' => ViewCourse::class,
    ])->assertActionHidden(TestAction::make('cancelEvent')->table($event));
});
