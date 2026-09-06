<?php

declare(strict_types=1);

use App\Actions\Events\CancelEvent;
use App\Actions\Events\ManageEventSubstitution;
use App\Actions\Events\ManageEventTeacherAssignments;
use App\Enums\EventSubstituteCoverageStatus;
use App\Enums\EventSubstituteRequestReason;
use App\Enums\EventSubstituteRequestStatus;
use App\Filament\Actions\EventSubstituteActions;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\Pages\ViewEvent;
use App\Filament\Admin\Widgets\SubstituteCoverageReminder;
use App\Filament\Shared\Widgets\CalendarWidget;
use App\Models\Calendar;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Livewire\Livewire;

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
        'Teacher unavailable.',
    );

    expect($request->status)->toBe(EventSubstituteRequestStatus::Pending)
        ->and($event->refresh()->substitute_needed_at)->not->toBeNull()
        ->and($event->substitute_teacher_id)->toBeNull()
        ->and($event->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::AwaitingResponse);

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'event-substitute-request'
        && $mail->hasTo('substitute@example.com')
        && str_contains($mail->getRenderedEmail()->html, 'Teacher unavailable.'));
});

it('only allows users with the teacher role to be requested', function (): void {
    $event = futureSubstituteEvent();
    $candidate = User::factory()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    app(ManageEventSubstitution::class)->requestSubstitute($event, $candidate, $actor);
})->throws(DomainException::class, 'Only users with the teacher role may be substitutes.');

it('blocks teachers who have an overlapping regular or substitute assignment', function (string $commitment): void {
    $teacher = User::factory()->isTeacher()->create();
    $event = futureSubstituteEvent();
    $conflict = Event::factory()->create([
        'course_id' => null,
        'start_time' => $event->start_time->copy()->addMinutes(15),
        'end_time' => $event->end_time->copy()->addMinutes(15),
    ]);

    match ($commitment) {
        'teacher' => app(ManageEventTeacherAssignments::class)->assignCustom($conflict, [$teacher->id]),
        'attendee' => EventAttendee::factory()->forUser($teacher)->create(['event_id' => $conflict->id]),
        'substitute' => $conflict->update(['substitute_teacher_id' => $teacher->id]),
        default => throw new LogicException("Unknown commitment type: {$commitment}"),
    };

    $actor = auth()->user();
    expect($actor)->toBeInstanceOf(User::class);

    app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
})->with(['teacher', 'attendee', 'substitute'])->throws(DomainException::class, 'overlapping event');

it('does not allow a teacher assigned to the event to be requested as a substitute', function (): void {
    $event = futureSubstituteEvent();
    $coveredTeacher = $event->teachers()->firstOrFail();
    $coTeacher = User::factory()->isTeacher()->create();
    $actor = auth()->user();
    app(ManageEventTeacherAssignments::class)->assignCustom($event, [
        $coveredTeacher->id,
        $coTeacher->id,
    ]);

    expect($actor)->toBeInstanceOf(User::class);

    app(ManageEventSubstitution::class)->requestSubstitute(
        $event,
        $coveredTeacher,
        $coTeacher,
        $actor,
    );
})->throws(DomainException::class, 'already teaching this event');

it('rechecks substitute availability when a teacher accepts', function (): void {
    $event = futureSubstituteEvent();
    $candidate = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);
    $request = app(ManageEventSubstitution::class)->requestSubstitute($event, $candidate, $actor);
    $conflict = Event::factory()->standalone()->create([
        'start_time' => $event->start_time?->copy()->addMinutes(15),
        'end_time' => $event->end_time?->copy()->addMinutes(15),
    ]);
    app(ManageEventTeacherAssignments::class)->assignCustom($conflict, [$candidate->id]);

    app(ManageEventSubstitution::class)->respond($request, $candidate, true);
})->throws(DomainException::class, 'overlapping event');

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

it('shows confirmed substitute assignments in events and exposes view-only lesson details', function (): void {
    Filament::setCurrentPanel('admin');
    $myCalendar = Calendar::query()->where('slug', Calendar::SLUG_MY)->firstOrFail();
    $course = Course::factory()->create();
    $event = Event::factory()->create([
        'name' => 'Kinderballet Substitute Class',
        'course_id' => $course->id,
        'details' => 'Practice the recital entrance.',
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $event->update(['name' => 'Kinderballet Substitute Class']);
    $teacher = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    $request = app(ManageEventSubstitution::class)->requestSubstitute($event, $teacher, $actor);
    app(ManageEventSubstitution::class)->respond($request, $teacher, true);

    $this->actingAs($teacher);

    expect(Gate::allows('view', $event->refresh()))->toBeTrue()
        ->and(Gate::allows('update', $event))->toBeFalse()
        ->and(Gate::allows('cancel', $event))->toBeFalse();

    livewire(ListEvents::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$event]);

    expect(EventResource::getGlobalSearchEloquentQuery()->whereKey($event)->exists())->toBeTrue()
        ->and(EventResource::canView($event))->toBeTrue()
        ->and(EventResource::getGlobalSearchResultUrl($event))->not->toBeNull()
        ->and(EventResource::getGlobalSearchResults('Kinderballet'))->toHaveCount(1);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->assertSee('Practice the recital entrance.')
        ->assertSchemaComponentDoesNotExist('updated_at', 'infolist')
        ->assertActionVisible('requestRelease')
        ->assertActionHidden(EditAction::class)
        ->assertActionHidden('sendEmail')
        ->assertActionHidden(TestAction::make('emailAttendance')->table());

    $eventUrl = EventResource::getUrl('view', ['record' => $event]);

    livewire(CalendarWidget::class)
        ->call('selectCalendar', $myCalendar->id)
        ->call('onEventClick', ['id' => $event->id])
        ->assertActionMounted('view')
        ->assertSchemaComponentVisible('details', 'mountedActionSchema0')
        ->assertActionDataSet(fn (array $data): bool => ($data['details'] ?? null) === 'Practice the recital entrance.')
        ->assertActionDoesNotExist('viewSubstituteEventDetails')
        ->assertActionVisible('viewFullEvent')
        ->assertActionHasUrl('viewFullEvent', $eventUrl);
});

it('reminds teachers on the dashboard about classes needing substitute coverage', function (): void {
    Filament::setCurrentPanel('admin');
    $teacher = User::factory()->isTeacher()->create();
    $candidate = User::factory()->isTeacher()->create();
    $course = Course::factory()->create();
    $course->teachers()->sync([$teacher->id]);
    $otherCourse = Course::factory()->create();

    $needsSubstitute = Event::factory()->create([
        'name' => 'Needs Coverage',
        'course_id' => $course->id,
        'substitute_needed_at' => now(),
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $awaitingResponse = Event::factory()->create([
        'name' => 'Awaiting Coverage',
        'course_id' => $course->id,
        'substitute_needed_at' => now(),
        'start_time' => now()->addDays(2),
        'end_time' => now()->addDays(2)->addHour(),
    ]);
    EventSubstituteRequest::factory()->create([
        'event_id' => $awaitingResponse->id,
        'teacher_id' => $candidate->id,
        'status' => EventSubstituteRequestStatus::Pending,
    ]);
    $otherTeacherEvent = Event::factory()->create([
        'name' => 'Other Teacher Coverage',
        'course_id' => $otherCourse->id,
        'substitute_needed_at' => now(),
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $pastEvent = Event::factory()->create([
        'name' => 'Past Coverage',
        'course_id' => $course->id,
        'substitute_needed_at' => now()->subDay(),
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);
    $notNeeded = Event::factory()->create([
        'name' => 'Coverage Not Needed',
        'course_id' => $course->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(3)->addHour(),
    ]);

    $this->actingAs($teacher);

    expect($needsSubstitute->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::NeedsSubstitute)
        ->and($awaitingResponse->substituteCoverageStatus())->toBe(EventSubstituteCoverageStatus::AwaitingResponse)
        ->and(SubstituteCoverageReminder::canView())->toBeTrue();

    $this->get(Dashboard::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSeeLivewire(SubstituteCoverageReminder::class)
        ->assertSeeText('2 Upcoming Classes Need Substitute Coverage')
        ->assertSeeText('Review Events');

    $query = parse_url((new SubstituteCoverageReminder)->eventsUrl(), PHP_URL_QUERY);
    expect($query)->toBeString();
    parse_str($query, $queryParams);

    Livewire::withQueryParams($queryParams)
        ->test(ListEvents::class)
        ->loadTable()
        ->assertSet('tableFilters.substitute_coverage.values', [
            EventSubstituteCoverageStatus::NeedsSubstitute->value,
            EventSubstituteCoverageStatus::AwaitingResponse->value,
        ])
        ->assertCanSeeTableRecords([$needsSubstitute, $awaitingResponse, $pastEvent])
        ->assertCanNotSeeTableRecords([$otherTeacherEvent, $notNeeded]);
});

it('filters events by any of the selected substitute coverage statuses', function (): void {
    Filament::setCurrentPanel('admin');
    $teacher = User::factory()->isTeacher()->create();
    $replacement = User::factory()->isTeacher()->create();
    $notNeeded = futureSubstituteEvent();
    $needsSubstitute = futureSubstituteEvent();
    $needsSubstitute->update(['substitute_needed_at' => now()]);
    $awaitingResponse = futureSubstituteEvent();
    EventSubstituteRequest::factory()->create([
        'event_id' => $awaitingResponse->id,
        'teacher_id' => $teacher->id,
    ]);
    $confirmed = futureSubstituteEvent();
    $confirmed->update(['substitute_teacher_id' => $teacher->id]);
    $replacementPending = futureSubstituteEvent();
    $replacementPending->update(['substitute_teacher_id' => $teacher->id]);
    EventSubstituteRequest::factory()->create([
        'event_id' => $replacementPending->id,
        'teacher_id' => $replacement->id,
    ]);
    $releaseRequested = futureSubstituteEvent();
    $releaseRequested->update(['substitute_teacher_id' => $teacher->id]);
    EventSubstituteRequest::factory()->accepted()->create([
        'event_id' => $releaseRequested->id,
        'teacher_id' => $teacher->id,
        'release_requested_at' => now(),
    ]);
    $events = collect([
        EventSubstituteCoverageStatus::NotNeeded->value => $notNeeded,
        EventSubstituteCoverageStatus::NeedsSubstitute->value => $needsSubstitute,
        EventSubstituteCoverageStatus::AwaitingResponse->value => $awaitingResponse,
        EventSubstituteCoverageStatus::Confirmed->value => $confirmed,
        EventSubstituteCoverageStatus::ReplacementPending->value => $replacementPending,
        EventSubstituteCoverageStatus::ReleaseRequested->value => $releaseRequested,
    ]);

    foreach ($events as $status => $event) {
        expect($event->substituteCoverageStatus()->value)->toBe($status)
            ->and(Event::query()->withSubstituteCoverageStatuses([$status])->pluck('id')->all())
            ->toBe([$event->id]);
    }

    $component = livewire(ListEvents::class)
        ->loadTable()
        ->assertTableColumnExists(
            'name',
            fn (TextColumn $column): bool => $column->getIcon($column->getState()) === Heroicon::OutlinedUser
                && $column->getIconPosition() === IconPosition::Before,
            $confirmed,
        )
        ->assertTableColumnExists(
            'name',
            fn (TextColumn $column): bool => $column->getIcon($column->getState()) === null,
            $notNeeded,
        );
    $filters = $component->instance()->getTable()->getFilters();
    $coverageFilter = $filters['substitute_coverage'] ?? null;

    expect($filters)->toHaveCount(1)
        ->and($coverageFilter)->toBeInstanceOf(SelectFilter::class);
    assert($coverageFilter instanceof SelectFilter);
    expect($coverageFilter->isMultiple())->toBeTrue();

    $component
        ->filterTable('substitute_coverage', [
            EventSubstituteCoverageStatus::NeedsSubstitute,
            EventSubstituteCoverageStatus::Confirmed,
        ])
        ->assertCanSeeTableRecords([$needsSubstitute, $confirmed])
        ->assertCanNotSeeTableRecords([
            $notNeeded,
            $awaitingResponse,
            $replacementPending,
            $releaseRequested,
        ]);
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
            'covered_teacher_id' => $event->teachers()->value('users.id'),
            'teacher_id' => $teacher->id,
            'reason_type' => EventSubstituteRequestReason::Other->value,
            'reason' => 'Please cover this class.',
        ])
        ->assertNotified('Substitute request sent')
        ->assertActionVisible('resendEventSubstituteRequest')
        ->assertActionVisible('withdrawEventSubstituteRequest');

    expect($event->refresh()->pendingSubstituteRequest()?->teacher_id)->toBe($teacher->id)
        ->and($event->pendingSubstituteRequest()?->request_reason)->toBe('Please cover this class.');

    livewire(ListEvents::class)
        ->loadTable()
        ->assertActionDoesNotExist(TestAction::make('markSubstituteNeeded')->table($event))
        ->assertTableColumnStateSet(
            'substitute_coverage_status',
            EventSubstituteCoverageStatus::AwaitingResponse->getLabel(),
            $event,
        );
});

it('offers only available teachers in the request substitute action', function (): void {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $event = futureSubstituteEvent();
    $assignedTeacher = $event->teachers()->firstOrFail();
    $currentSubstitute = User::factory()->isTeacher()->create();
    $busyTeacher = User::factory()->isTeacher()->create();
    $busyAttendee = User::factory()->isTeacher()->create();
    $busySubstitute = User::factory()->isTeacher()->create();
    $availableTeacher = User::factory()->isTeacher()->create();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);
    $currentRequest = app(ManageEventSubstitution::class)->requestSubstitute($event, $currentSubstitute, $actor);
    app(ManageEventSubstitution::class)->respond($currentRequest, $currentSubstitute, true);

    $regularConflict = Event::factory()->standalone()->create([
        'start_time' => $event->start_time?->copy()->addMinutes(10),
        'end_time' => $event->end_time?->copy()->addMinutes(10),
    ]);
    app(ManageEventTeacherAssignments::class)->assignCustom($regularConflict, [$busyTeacher->id]);
    EventAttendee::factory()->forUser($busyAttendee)->create(['event_id' => $regularConflict->id]);

    $substituteConflict = futureSubstituteEvent();
    $busyRequest = app(ManageEventSubstitution::class)->requestSubstitute($substituteConflict, $busySubstitute, $actor);
    app(ManageEventSubstitution::class)->respond($busyRequest, $busySubstitute, true);

    livewire(ViewEvent::class, ['record' => $event->id])
        ->assertSee('Coverage by Teacher')
        ->assertDontSee('Regular Teacher')
        ->assertActionExists(
            'requestEventSubstitute',
            fn (Action $action): bool => $action->getLabel() === 'Request Substitute'
                && $action->getModalHeading() === 'Request Substitute',
        )
        ->mountAction('requestEventSubstitute')
        ->assertSchemaComponentExists(
            'covered_teacher_id',
            'mountedActionSchema0',
            fn (Select $select): bool => $select->getLabel() === 'Teacher Being Covered',
        )
        ->assertSchemaComponentExists(
            'teacher_id',
            'mountedActionSchema0',
            function (Select $select) use ($assignedTeacher, $availableTeacher, $busyAttendee, $busySubstitute, $busyTeacher, $currentSubstitute): bool {
                $options = $select->getOptions();

                return array_key_exists($availableTeacher->id, $options)
                    && ! array_key_exists($assignedTeacher->id, $options)
                    && ! array_key_exists($currentSubstitute->id, $options)
                    && ! array_key_exists($busyTeacher->id, $options)
                    && ! array_key_exists($busyAttendee->id, $options)
                    && ! array_key_exists($busySubstitute->id, $options);
            },
        );
});

it('records the selected substitute reason and only shows optional details for other', function (): void {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $sickEvent = futureSubstituteEvent();
    $otherEvent = futureSubstituteEvent();
    $sickTeacher = User::factory()->isTeacher()->create();
    $otherTeacher = User::factory()->isTeacher()->create();

    livewire(ViewEvent::class, ['record' => $otherEvent->id])
        ->mountAction('requestEventSubstitute')
        ->assertSchemaComponentExists(
            'reason_type',
            'mountedActionSchema0',
            fn (Select $select): bool => $select->getOptions() === [
                EventSubstituteRequestReason::Sick->value => 'Sick',
                EventSubstituteRequestReason::Other->value => 'Other',
            ] && $select->isRequired(),
        )
        ->assertSchemaComponentHidden('reason', 'mountedActionSchema0')
        ->setActionData([
            'covered_teacher_id' => $otherEvent->teachers()->value('users.id'),
            'teacher_id' => $otherTeacher->id,
            'reason_type' => EventSubstituteRequestReason::Other->value,
        ])
        ->assertSchemaComponentVisible('reason', 'mountedActionSchema0')
        ->assertSchemaComponentExists(
            'reason',
            'mountedActionSchema0',
            fn (Textarea $textarea): bool => ! $textarea->isRequired(),
        )
        ->callMountedAction()
        ->assertNotified('Substitute request sent');

    livewire(ViewEvent::class, ['record' => $sickEvent->id])
        ->callAction('requestEventSubstitute', [
            'covered_teacher_id' => $sickEvent->teachers()->value('users.id'),
            'teacher_id' => $sickTeacher->id,
            'reason_type' => EventSubstituteRequestReason::Sick->value,
            'reason' => 'This hidden detail should not be saved.',
        ])
        ->assertNotified('Substitute request sent');

    expect($otherEvent->refresh()->pendingSubstituteRequest()?->request_reason)->toBe('Other')
        ->and($sickEvent->refresh()->pendingSubstituteRequest()?->request_reason)->toBe('Sick');
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
        ->and($group->getDropdownWidth())->toBe(Width::ExtraSmall);

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
    $event = Event::factory()->create([
        'course_id' => null,
        'start_time' => now()->addDay(),
        'end_time' => now()->addDay()->addHour(),
    ]);
    $regularTeacher = User::factory()->isTeacher()->create();
    app(ManageEventTeacherAssignments::class)->assignCustom($event, [$regularTeacher->id]);

    return $event;
}
