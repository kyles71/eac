<?php

declare(strict_types=1);

use App\Actions\Events\CancelEvent;
use App\Actions\Events\ManageEventSubstitution;
use App\Enums\EventSubstituteCoverageStatus;
use App\Enums\EventSubstituteRequestStatus;
use App\Filament\Actions\EventSubstituteActions;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;

use function Pest\Livewire\livewire;

it('requests a teacher substitute and queues the customizable request email', function (): void {
    Mail::fake();
    $event = futureSubstituteEvent();
    $teacher = User::factory()->isTeacher()->create(['email' => 'substitute@example.com']);
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    $request = app(ManageEventSubstitution::class)->requestSubstitute(
        $event,
        $teacher,
        $actor,
        'Regular teacher unavailable.',
    );

    expect($request->status)->toBe(EventSubstituteRequestStatus::Pending)
        ->and($event->refresh()->substitute_needed_at)->not->toBeNull()
        ->and($event->substitute_teacher_id)->toBeNull()
        ->and($event->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::AwaitingResponse);

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'event-substitute-request'
        && $mail->hasTo('substitute@example.com')
        && str_contains($mail->getRenderedEmail()->html, 'Regular teacher unavailable.'));
});

it('only allows users with the teacher role to be requested', function (): void {
    $event = futureSubstituteEvent();
    $candidate = User::factory()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    app(ManageEventSubstitution::class)->requestSubstitute($event, $candidate, $actor);
})->throws(DomainException::class, 'Only users with the teacher role may be substitutes.');

it('blocks teachers who have an overlapping teaching attendee or substitute assignment', function (string $commitment): void {
    $teacher = User::factory()->isTeacher()->create();
    $event = futureSubstituteEvent();
    $conflict = Event::factory()->create([
        'course_id' => null,
        'start_time' => $event->start_time->copy()->addMinutes(15),
        'end_time' => $event->end_time->copy()->addMinutes(15),
    ]);

    match ($commitment) {
        'teacher' => tap(Course::factory()->create(), function (Course $course) use ($conflict, $teacher): void {
            $course->teachers()->sync([$teacher->id]);
            $conflict->update(['course_id' => $course->id]);
        }),
        'attendee' => EventAttendee::factory()->forUser($teacher)->create(['event_id' => $conflict->id]),
        'substitute' => $conflict->update(['substitute_teacher_id' => $teacher->id]),
        default => throw new LogicException("Unknown commitment type: {$commitment}"),
    };

    $actor = auth()->user();
    expect($actor)->toBeInstanceOf(User::class);

    app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
})->with(['teacher', 'attendee', 'substitute'])->throws(DomainException::class, 'overlapping event');

it('accepts a request and grants only record-specific substitute abilities', function (): void {
    $event = futureSubstituteEvent();
    $teacher = User::factory()->isTeacher()->create();
    $otherTeacher = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);
    $request = app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
    app(ManageEventSubstitution::class)->respond($request, $teacher, true);

    expect($request->refresh()->status)->toBe(EventSubstituteRequestStatus::Accepted)
        ->and($event->refresh()->substitute_teacher_id)->toBe($teacher->id)
        ->and($event->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::Confirmed)
        ->and(Gate::forUser($teacher)->allows('viewSubstituteDetails', $event))->toBeTrue()
        ->and(Gate::forUser($teacher)->allows('recordSubstituteAttendance', $event))->toBeTrue()
        ->and(Gate::forUser($otherTeacher)->allows('viewSubstituteDetails', $event))->toBeFalse();
});

it('shows a confirmed assignment on the substitute teacher my calendar even when ordinarily excluded', function (): void {
    $myCalendar = Calendar::query()->where('slug', Calendar::SLUG_MY)->firstOrFail();
    $event = futureSubstituteEvent();
    $teacher = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);
    $request = app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
    app(ManageEventSubstitution::class)->respond($request, $teacher, true);
    $event->excludedUsers()->sync([$teacher->id]);

    expect(Event::query()
        ->visibleOnCalendar($myCalendar, $teacher)
        ->whereKey($event->id)
        ->exists())->toBeTrue();
});

it('declines a request without filling the coverage need', function (): void {
    $event = futureSubstituteEvent();
    $teacher = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);
    $request = app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
    app(ManageEventSubstitution::class)->respond($request, $teacher, false, 'Already committed elsewhere.');

    expect($request->refresh()->status)->toBe(EventSubstituteRequestStatus::Declined)
        ->and($request->response_note)->toBe('Already committed elsewhere.')
        ->and($event->refresh()->substitute_teacher_id)->toBeNull()
        ->and($event->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::NeedsSubstitute);
});

it('keeps the current substitute until a replacement accepts and then notifies the outgoing teacher', function (): void {
    Mail::fake();
    $event = futureSubstituteEvent();
    $original = User::factory()->isTeacher()->create(['email' => 'original@example.com']);
    $replacement = User::factory()->isTeacher()->create(['email' => 'replacement@example.com']);
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);
    $originalRequest = app(ManageEventSubstitution::class)->requestSubstitute($event, $original, $actor);
    app(ManageEventSubstitution::class)->respond($originalRequest, $original, true);
    $replacementRequest = app(ManageEventSubstitution::class)->requestSubstitute(
        $event->refresh(),
        $replacement,
        $actor,
        'Original substitute is unavailable.',
    );

    expect($event->refresh()->substitute_teacher_id)->toBe($original->id)
        ->and($event->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::ReplacementPending);

    app(ManageEventSubstitution::class)->respond($replacementRequest, $replacement, true);

    expect($event->refresh()->substitute_teacher_id)->toBe($replacement->id)
        ->and($originalRequest->refresh()->status)->toBe(EventSubstituteRequestStatus::Replaced)
        ->and(Gate::forUser($original)->allows('viewSubstituteDetails', $event))->toBeFalse()
        ->and(Gate::forUser($replacement)->allows('viewSubstituteDetails', $event))->toBeTrue();

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'event-substitute-removed'
        && $mail->hasTo('original@example.com'));
});

it('records a release request without immediately removing access', function (): void {
    $event = futureSubstituteEvent();
    $teacher = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);
    $request = app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
    app(ManageEventSubstitution::class)->respond($request, $teacher, true);
    app(ManageEventSubstitution::class)->requestRelease($event->refresh(), $teacher, 'I am ill.');

    expect($request->refresh()->release_reason)->toBe('I am ill.')
        ->and($event->refresh()->substitute_teacher_id)->toBe($teacher->id)
        ->and($event->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::ReleaseRequested)
        ->and(Gate::forUser($teacher)->allows('viewSubstituteDetails', $event))->toBeTrue();
});

it('allows owners to make silent historical corrections after an event ends', function (): void {
    Mail::fake();
    $event = Event::factory()->create([
        'course_id' => null,
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);
    $teacher = User::factory()->isTeacher()->create();
    $owner = auth()->user();

    expect($owner)->toBeInstanceOf(User::class);
    app(ManageEventSubstitution::class)->recordHistoricalCorrection(
        $event,
        $teacher,
        $owner,
        'Recorded after a last-minute change.',
    );

    expect($event->refresh()->substitute_teacher_id)->toBe($teacher->id)
        ->and($event->currentSubstituteRequest()?->response_recorded_by_user_id)->toBe($owner->id)
        ->and(Gate::forUser($teacher)->allows('recordSubstituteAttendance', $event))->toBeTrue()
        ->and(Gate::forUser($teacher)->allows('requestSubstituteRelease', $event))->toBeFalse();
    Mail::assertNothingQueued();
});

it('withdraws pending requests and retains confirmed substitute history when an event is cancelled', function (): void {
    Mail::fake();
    $event = futureSubstituteEvent();
    $teacher = User::factory()->isTeacher()->create(['email' => 'confirmed-sub@example.com']);
    $replacement = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);
    $accepted = app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
    app(ManageEventSubstitution::class)->respond($accepted, $teacher, true);
    $pending = app(ManageEventSubstitution::class)->requestSubstitute($event->refresh(), $replacement, $actor, 'Replacement needed.');

    app(CancelEvent::class)->handle($event->refresh(), $actor, 'Studio closed.', true);

    expect($pending->refresh()->status)->toBe(EventSubstituteRequestStatus::Withdrawn)
        ->and($event->refresh()->substitute_teacher_id)->toBe($teacher->id)
        ->and($event->substitute_needed_at)->toBeNull()
        ->and(Gate::forUser($teacher)->allows('viewSubstituteDetails', $event))->toBeTrue()
        ->and(Gate::forUser($teacher)->allows('recordSubstituteAttendance', $event))->toBeFalse();

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'event-cancellation'
        && $mail->hasTo('confirmed-sub@example.com'));
});

it('manages substitute coverage through explicit admin event actions and table status', function (): void {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $event = futureSubstituteEvent();
    $teacher = User::factory()->isTeacher()->create();

    livewire(ViewEvent::class, ['record' => $event->id])
        ->assertActionVisible('markSubstituteNeeded')
        ->assertActionVisible('requestEventSubstitute')
        ->callAction(TestAction::make('requestEventSubstitute'), [
            'teacher_id' => $teacher->id,
            'reason' => 'Please cover this class.',
        ])
        ->assertNotified('Substitute request sent')
        ->assertActionVisible('resendEventSubstituteRequest')
        ->assertActionVisible('withdrawEventSubstituteRequest');

    expect($event->refresh()->pendingSubstituteRequest()?->teacher_id)->toBe($teacher->id);

    livewire(ListEvents::class)
        ->loadTable()
        ->assertActionDoesNotExist(TestAction::make('markSubstituteNeeded')->table($event))
        ->assertTableColumnStateSet(
            'substitute_coverage_status',
            EventSubstituteCoverageStatus::AwaitingResponse,
            $event,
        );
});

it('changes the substitute action group appearance with the coverage state', function (): void {
    $event = futureSubstituteEvent();
    $teacher = User::factory()->isTeacher()->create();
    $replacement = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    $group = EventSubstituteActions::group()->record($event);
    expect($group->getLabel())->toBe('Substitute: Not Needed')
        ->and($group->getColor())->toBe('gray')
        ->and($group->getIcon())->toBe(Heroicon::OutlinedAcademicCap)
        ->and($group->getDropdownWidth())->toBe(Width::Medium);

    app(ManageEventSubstitution::class)->markNeeded($event, $actor);
    $group = EventSubstituteActions::group()->record($event->refresh());
    expect($group->getLabel())->toBe('Substitute: Needs Substitute')
        ->and($group->getColor())->toBe('danger')
        ->and($group->getIcon())->toBe(Heroicon::OutlinedExclamationCircle);

    $request = app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
    $group = EventSubstituteActions::group()->record($event->refresh());
    expect($group->getLabel())->toBe('Substitute: Awaiting Response')
        ->and($group->getColor())->toBe('warning')
        ->and($group->getIcon())->toBe(Heroicon::OutlinedClock);

    app(ManageEventSubstitution::class)->respond($request, $teacher, true);
    $group = EventSubstituteActions::group()->record($event->refresh());
    expect($group->getLabel())->toBe('Substitute: Confirmed')
        ->and($group->getColor())->toBe('success')
        ->and($group->getIcon())->toBe(Heroicon::OutlinedCheckCircle);

    app(ManageEventSubstitution::class)->requestSubstitute($event, $replacement, $actor, 'Replacement needed.');
    $group = EventSubstituteActions::group()->record($event->refresh());
    expect($group->getLabel())->toBe('Substitute: Replacement Pending')
        ->and($group->getColor())->toBe('warning')
        ->and($group->getIcon())->toBe(Heroicon::OutlinedArrowPath);

    app(ManageEventSubstitution::class)->requestRelease($event, $teacher, 'Unable to cover.');
    $group = EventSubstituteActions::group()->record($event->refresh());
    expect($group->getLabel())->toBe('Substitute: Release Requested')
        ->and($group->getColor())->toBe('danger')
        ->and($group->getIcon())->toBe(Heroicon::OutlinedExclamationTriangle);
});

function futureSubstituteEvent(): Event
{
    return Event::factory()->create([
        'course_id' => null,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
}
