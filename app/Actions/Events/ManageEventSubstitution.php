<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Mail\QueueManagedEmail;
use App\Enums\EventSubstituteRequestReason;
use App\Enums\EventSubstituteRequestStatus;
use App\Models\Event;
use App\Models\EventSubstituteCoverage;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use App\Services\Mail\EventSubstituteContentService;
use App\Services\TeacherScheduleConflictService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final readonly class ManageEventSubstitution
{
    public function __construct(
        private TeacherScheduleConflictService $conflicts,
        private ManageEventTeacherAssignments $teacherAssignments,
        private EventSubstituteContentService $content,
        private QueueManagedEmail $managedEmail,
    ) {}

    public function markNeeded(Event $event, User $coveredTeacher, ?User $actor = null): EventSubstituteCoverage
    {
        if (! $actor instanceof User) {
            $actor = $coveredTeacher;
            $coveredTeacher = $this->onlyRegularTeacher($event, $actor);
        }

        return DB::transaction(function () use ($event, $coveredTeacher, $actor): EventSubstituteCoverage {
            $lockedEvent = $this->lockedEvent($event);
            Gate::forUser($actor)->authorize('update', $lockedEvent);
            $this->ensureRequestable($lockedEvent);
            $this->ensureCoveredTeacher($lockedEvent, $coveredTeacher);
            $this->teacherAssignments->pinForSubstituteCoverage($lockedEvent);

            return $this->ensureActiveCoverage($lockedEvent, $coveredTeacher);
        });
    }

    public function requestSubstitute(
        Event $event,
        User $coveredTeacherOrSubstitute,
        User $substituteOrRequestedBy,
        User|string|null $requestedByOrReason = null,
        ?string $reason = null,
        ?EventSubstituteRequestReason $reasonType = null,
    ): EventSubstituteRequest {
        if ($requestedByOrReason instanceof User) {
            $coveredTeacher = $coveredTeacherOrSubstitute;
            $substitute = $substituteOrRequestedBy;
            $requestedBy = $requestedByOrReason;
        } else {
            $substitute = $coveredTeacherOrSubstitute;
            $requestedBy = $substituteOrRequestedBy;
            $reason = is_string($requestedByOrReason) ? $requestedByOrReason : $reason;
            $this->ensureTeacher($substitute);
            $coveredTeacher = $this->onlyRegularTeacher($event, $requestedBy);
        }

        return DB::transaction(function () use ($event, $coveredTeacher, $substitute, $requestedBy, $reason, $reasonType): EventSubstituteRequest {
            $lockedEvent = $this->lockedEvent($event);
            Gate::forUser($requestedBy)->authorize('update', $lockedEvent);
            $this->ensureRequestable($lockedEvent);
            $this->ensureCoveredTeacher($lockedEvent, $coveredTeacher);
            $this->ensureTeacher($substitute);

            if ($coveredTeacher->is($substitute)) {
                throw new DomainException('A teacher cannot substitute for their own assignment.');
            }

            $this->teacherAssignments->pinForSubstituteCoverage($lockedEvent);
            $coverage = $this->ensureActiveCoverage($lockedEvent, $coveredTeacher);

            if ($coverage->substitute_teacher_id === $substitute->id) {
                throw new DomainException('This teacher is already the confirmed substitute for this assignment.');
            }

            if ($coverage->pendingRequest() instanceof EventSubstituteRequest) {
                throw new DomainException('This teacher assignment already has a pending substitute request.');
            }

            $reason = $this->cleanText($reason);

            if ($coverage->substitute_teacher_id !== null && $reason === null) {
                throw new InvalidArgumentException('A replacement reason is required.');
            }

            $this->ensureAvailableSubstitute($lockedEvent, $substitute);
            $request = $coverage->requests()->create([
                'event_id' => $lockedEvent->id,
                'teacher_id' => $substitute->id,
                'requested_by_user_id' => $requestedBy->id,
                'status' => EventSubstituteRequestStatus::Pending,
                'reason_type' => $reasonType,
                'request_reason' => $reason,
                'sick_instructor_id' => $this->sickInstructorId($lockedEvent, $reasonType),
            ]);
            $this->queueRequestEmail($request);

            return $request;
        });
    }

    public function resend(EventSubstituteRequest $request, User $actor): bool
    {
        return DB::transaction(function () use ($request, $actor): bool {
            $lockedRequest = $this->lockedRequest($request);
            $event = $this->lockedEvent($lockedRequest->event);
            Gate::forUser($actor)->authorize('update', $event);

            if (! $lockedRequest->isPending() || ! $event->canAcceptSubstituteRequestAt()) {
                throw new DomainException('This substitute request can no longer be resent.');
            }

            $queued = $this->queueRequestEmail($lockedRequest);
            $lockedRequest->update(['reminder_processed_at' => now()]);

            return $queued;
        });
    }

    public function respond(EventSubstituteRequest $request, User $teacher, bool $accept, ?string $note = null): EventSubstituteRequest
    {
        return DB::transaction(function () use ($request, $teacher, $accept, $note): EventSubstituteRequest {
            $lockedRequest = $this->lockedRequest($request);
            $event = $this->lockedEvent($lockedRequest->event);
            $coverage = $this->lockedCoverage($lockedRequest->coverage);

            if ($lockedRequest->teacher_id !== $teacher->id) {
                throw new DomainException('This substitute request belongs to another teacher.');
            }

            if (! $lockedRequest->isPending()) {
                throw new DomainException('This substitute request has already been answered.');
            }

            if (! $coverage->isActive() || ! $event->canAcceptSubstituteRequestAt()) {
                throw new DomainException('This substitute request can no longer be answered.');
            }

            if (! $accept) {
                $lockedRequest->update([
                    'status' => EventSubstituteRequestStatus::Declined,
                    'response_note' => $this->cleanText($note),
                    'responded_at' => now(),
                    'response_recorded_by_user_id' => $teacher->id,
                    'closed_at' => now(),
                    'closed_by_user_id' => $teacher->id,
                ]);

                return $lockedRequest;
            }

            $this->ensureTeacher($teacher);
            $this->ensureAvailableSubstitute($event, $teacher);
            $outgoingRequest = $coverage->currentSubstituteRequest();

            if ($outgoingRequest instanceof EventSubstituteRequest && $outgoingRequest->teacher_id !== $teacher->id) {
                $this->closeRequest(
                    $outgoingRequest,
                    EventSubstituteRequestStatus::Replaced,
                    $teacher,
                    $lockedRequest->request_reason ?? 'A replacement substitute accepted the event.',
                );
            }

            $lockedRequest->update([
                'status' => EventSubstituteRequestStatus::Accepted,
                'response_note' => $this->cleanText($note),
                'responded_at' => now(),
                'response_recorded_by_user_id' => $teacher->id,
            ]);
            $coverage->update(['substitute_teacher_id' => $teacher->id]);

            if ($outgoingRequest instanceof EventSubstituteRequest && $outgoingRequest->teacher_id !== $teacher->id) {
                $this->queueRemovedEmail(
                    $outgoingRequest,
                    $lockedRequest->request_reason ?? 'A replacement substitute accepted the event.',
                );
            }

            return $lockedRequest;
        });
    }

    public function withdrawPending(Event|EventSubstituteCoverage $target, User $actor, string $reason): EventSubstituteRequest
    {
        return DB::transaction(function () use ($target, $actor, $reason): EventSubstituteRequest {
            $coverage = $this->coverageFromTarget($target, requirePending: true);
            $event = $this->lockedEvent($coverage->event);
            Gate::forUser($actor)->authorize('update', $event);
            $pendingRequest = $coverage->pendingRequest();

            if (! $pendingRequest instanceof EventSubstituteRequest) {
                throw new DomainException('This teacher assignment does not have a pending substitute request.');
            }

            $this->closeRequest($pendingRequest, EventSubstituteRequestStatus::Withdrawn, $actor, $this->requiredText($reason));

            return $pendingRequest;
        });
    }

    public function requestRelease(Event|EventSubstituteCoverage $target, User $teacher, string $reason): EventSubstituteRequest
    {
        return DB::transaction(function () use ($target, $teacher, $reason): EventSubstituteRequest {
            $coverage = $target instanceof Event
                ? $this->coverageForSubstitute($target, $teacher)
                : $this->lockedCoverage($target);

            if ($coverage->substitute_teacher_id !== $teacher->id || ! $coverage->isActive()) {
                throw new DomainException('You are not the confirmed substitute for this teacher assignment.');
            }

            $request = $coverage->currentSubstituteRequest();

            if (! $request instanceof EventSubstituteRequest) {
                throw new DomainException('The confirmed substitute assignment could not be found.');
            }

            $request->update([
                'release_requested_at' => now(),
                'release_reason' => $this->requiredText($reason),
            ]);

            return $request;
        });
    }

    public function dismissReleaseRequest(Event|EventSubstituteCoverage $target, User $actor): EventSubstituteRequest
    {
        return DB::transaction(function () use ($target, $actor): EventSubstituteRequest {
            $coverage = $this->coverageFromTarget($target, requireRelease: true);
            $event = $this->lockedEvent($coverage->event);
            Gate::forUser($actor)->authorize('update', $event);
            $request = $coverage->currentSubstituteRequest();

            if (! $request instanceof EventSubstituteRequest || ! $request->hasReleaseRequest()) {
                throw new DomainException('This teacher assignment does not have a substitute release request.');
            }

            $request->update([
                'release_requested_at' => null,
                'release_reason' => null,
            ]);

            return $request;
        });
    }

    public function removeCurrent(
        Event|EventSubstituteCoverage $target,
        User $actor,
        string $reason,
        bool $keepNeeded = true,
    ): EventSubstituteCoverage {
        return DB::transaction(function () use ($target, $actor, $reason, $keepNeeded): EventSubstituteCoverage {
            $coverage = $this->coverageFromTarget($target);
            $event = $this->lockedEvent($coverage->event);
            Gate::forUser($actor)->authorize('update', $event);

            if ($event->isCompletedAt()) {
                throw new DomainException('Completed events require an owner historical correction.');
            }

            $reason = $this->requiredText($reason);
            $pendingRequest = $coverage->pendingRequest();

            if ($pendingRequest instanceof EventSubstituteRequest) {
                $this->closeRequest($pendingRequest, EventSubstituteRequestStatus::Withdrawn, $actor, $reason);
            }

            $currentRequest = $coverage->currentSubstituteRequest();

            if ($currentRequest instanceof EventSubstituteRequest) {
                $this->closeRequest($currentRequest, EventSubstituteRequestStatus::Removed, $actor, $reason);
                $this->queueRemovedEmail($currentRequest, $reason);
            }

            $coverage->update($keepNeeded
                ? ['substitute_teacher_id' => null]
                : [
                    'substitute_teacher_id' => null,
                    'closed_at' => now(),
                    'closed_by_user_id' => $actor->id,
                    'closure_reason' => $reason,
                ]);

            return $coverage;
        });
    }

    public function recordHistoricalCorrection(
        Event $event,
        ?User $teacher,
        User $owner,
        string $reason,
        ?User $coveredTeacher = null,
    ): ?EventSubstituteRequest {
        return DB::transaction(function () use ($event, $teacher, $owner, $reason, $coveredTeacher): ?EventSubstituteRequest {
            $lockedEvent = $this->lockedEvent($event);
            Gate::forUser($owner)->authorize('update', $lockedEvent);

            if (! $owner->hasAnyRole(['owner', 'super_admin'])) {
                throw new DomainException('Only an owner can correct a completed substitute record.');
            }

            if (! $lockedEvent->isCompletedAt()) {
                throw new DomainException('Historical substitute corrections are only available after the event ends.');
            }

            $coveredTeacher ??= $this->onlyRegularTeacher($lockedEvent, $owner);
            $this->ensureCoveredTeacher($lockedEvent, $coveredTeacher);

            if ($teacher instanceof User) {
                $this->ensureTeacher($teacher);
            }

            $reason = $this->requiredText($reason);
            $coverage = $lockedEvent->substituteCoverages()
                ->where('covered_teacher_id', $coveredTeacher->id)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (! $coverage instanceof EventSubstituteCoverage) {
                $coverage = $lockedEvent->substituteCoverages()
                    ->whereNull('covered_teacher_id')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();
                $coverage?->update(['covered_teacher_id' => $coveredTeacher->id]);
            }

            $coverage ??= $lockedEvent->substituteCoverages()->create([
                'covered_teacher_id' => $coveredTeacher->id,
                'needed_at' => $lockedEvent->start_time ?? now(),
            ]);

            foreach ($coverage->requests()->pending()->get() as $pendingRequest) {
                $this->closeRequest($pendingRequest, EventSubstituteRequestStatus::Expired, $owner, $reason);
            }

            $currentRequest = $coverage->currentSubstituteRequest();

            if ($currentRequest instanceof EventSubstituteRequest) {
                $status = $teacher instanceof User
                    ? EventSubstituteRequestStatus::Replaced
                    : EventSubstituteRequestStatus::Removed;
                $this->closeRequest($currentRequest, $status, $owner, $reason);
            }

            $coverage->update([
                'substitute_teacher_id' => $teacher?->id,
                'closed_at' => now(),
                'closed_by_user_id' => $owner->id,
                'closure_reason' => $reason,
            ]);

            if (! $teacher instanceof User) {
                return null;
            }

            return $coverage->requests()->create([
                'event_id' => $lockedEvent->id,
                'teacher_id' => $teacher->id,
                'requested_by_user_id' => $owner->id,
                'response_recorded_by_user_id' => $owner->id,
                'status' => EventSubstituteRequestStatus::Accepted,
                'request_reason' => $reason,
                'response_note' => 'Recorded as an owner historical correction.',
                'responded_at' => now(),
            ]);
        });
    }

    private function activeCoverage(Event $event, User $coveredTeacher): ?EventSubstituteCoverage
    {
        return $event->substituteCoverages()
            ->active()
            ->where('covered_teacher_id', $coveredTeacher->id)
            ->lockForUpdate()
            ->first();
    }

    private function ensureActiveCoverage(Event $event, User $coveredTeacher): EventSubstituteCoverage
    {
        $coverage = $this->activeCoverage($event, $coveredTeacher)
            ?? $event->substituteCoverages()
                ->where('covered_teacher_id', $coveredTeacher->id)
                ->lockForUpdate()
                ->first();

        if (! $coverage instanceof EventSubstituteCoverage) {
            return $event->substituteCoverages()->create([
                'covered_teacher_id' => $coveredTeacher->id,
                'needed_at' => now(),
            ]);
        }

        if (! $coverage->isActive()) {
            $coverage->update([
                'needed_at' => now(),
                'substitute_teacher_id' => null,
                'closed_at' => null,
                'closed_by_user_id' => null,
                'closure_reason' => null,
            ]);
        }

        return $coverage;
    }

    private function coverageFromTarget(
        Event|EventSubstituteCoverage $target,
        bool $requirePending = false,
        bool $requireRelease = false,
    ): EventSubstituteCoverage {
        if ($target instanceof EventSubstituteCoverage) {
            return $this->lockedCoverage($target);
        }

        $query = $target->substituteCoverages()->active();

        if ($requirePending) {
            $query->whereHas('requests', fn (Builder $query): Builder => $query
                ->where('status', EventSubstituteRequestStatus::Pending));
        }

        if ($requireRelease) {
            $query->whereHas('requests', fn (Builder $query): Builder => $query
                ->where('status', EventSubstituteRequestStatus::Accepted)
                ->whereNotNull('release_requested_at'));
        }

        $coverages = $query->lockForUpdate()->get();

        if ($coverages->count() !== 1) {
            throw new DomainException('Select the teacher coverage to update.');
        }

        return $coverages->firstOrFail();
    }

    private function coverageForSubstitute(Event $event, User $teacher): EventSubstituteCoverage
    {
        $coverages = $event->substituteCoverages()
            ->active()
            ->where('substitute_teacher_id', $teacher->id)
            ->lockForUpdate()
            ->get();

        if ($coverages->count() !== 1) {
            throw new DomainException('Select the teacher coverage to update.');
        }

        return $coverages->firstOrFail();
    }

    private function lockedCoverage(EventSubstituteCoverage $coverage): EventSubstituteCoverage
    {
        $lockedCoverage = EventSubstituteCoverage::query()
            ->with('event')
            ->lockForUpdate()
            ->find($coverage->getKey());

        if (! $lockedCoverage instanceof EventSubstituteCoverage) {
            throw new InvalidArgumentException('The substitute coverage could not be found.');
        }

        return $lockedCoverage;
    }

    private function lockedEvent(Event $event): Event
    {
        $lockedEvent = Event::query()->lockForUpdate()->find($event->getKey());

        if (! $lockedEvent instanceof Event) {
            throw new InvalidArgumentException('The event could not be found.');
        }

        return $lockedEvent;
    }

    private function sickInstructorId(Event $event, ?EventSubstituteRequestReason $reasonType): ?int
    {
        if ($reasonType !== EventSubstituteRequestReason::Sick || $event->course_id === null) {
            return null;
        }

        $teacherIds = $event->course()
            ->firstOrFail()
            ->teachers()
            ->limit(2)
            ->pluck('users.id');

        return $teacherIds->count() === 1 ? (int) $teacherIds->first() : null;
    }

    private function lockedRequest(EventSubstituteRequest $request): EventSubstituteRequest
    {
        $lockedRequest = EventSubstituteRequest::query()
            ->with(['event', 'coverage'])
            ->lockForUpdate()
            ->find($request->getKey());

        if (! $lockedRequest instanceof EventSubstituteRequest
            || ! $lockedRequest->coverage instanceof EventSubstituteCoverage) {
            throw new InvalidArgumentException('The substitute request could not be found.');
        }

        return $lockedRequest;
    }

    private function onlyRegularTeacher(Event $event, ?User $legacyFallback = null): User
    {
        $teachers = $event->teachers()->get();

        if ($teachers->isEmpty()
            && $event->course_id === null
            && $legacyFallback instanceof User
            && $legacyFallback->hasAnyRole(['teacher', 'owner', 'super_admin'])) {
            $this->teacherAssignments->assignCustom($event, [$legacyFallback->id]);

            return $legacyFallback;
        }

        if ($teachers->count() !== 1) {
            throw new DomainException('Select the teacher being covered.');
        }

        return $teachers->firstOrFail();
    }

    private function ensureCoveredTeacher(Event $event, User $teacher): void
    {
        if (! $event->teacherAssignments()->where('teacher_id', $teacher->id)->exists()) {
            throw new DomainException('The covered teacher must be assigned to this event.');
        }
    }

    private function ensureRequestable(Event $event): void
    {
        if (! $event->canAcceptSubstituteRequestAt()) {
            throw new DomainException('Substitute requests require a scheduled, non-cancelled event that has not ended.');
        }
    }

    private function ensureTeacher(User $teacher): void
    {
        if (! $teacher->hasRole('teacher')) {
            throw new DomainException('Only users with the teacher role may be substitutes.');
        }
    }

    private function ensureAvailableSubstitute(Event $event, User $teacher): void
    {
        if ($event->isAssignedTeacher($teacher)) {
            throw new DomainException("{$teacher->fullName} is already teaching this event.");
        }

        if ($event->activeSubstituteCoverages()
            ->where('substitute_teacher_id', $teacher->id)
            ->exists()) {
            throw new DomainException("{$teacher->fullName} is already substituting for this event.");
        }

        $conflict = $this->conflicts->conflictingEvent($event, $teacher);

        if ($conflict instanceof Event) {
            throw new DomainException("{$teacher->fullName} is already assigned to an overlapping event: {$conflict->name}.");
        }
    }

    private function closeRequest(
        EventSubstituteRequest $request,
        EventSubstituteRequestStatus $status,
        ?User $actor,
        string $reason,
    ): void {
        $request->update([
            'status' => $status,
            'closed_at' => now(),
            'closed_by_user_id' => $actor?->id,
            'closure_reason' => $reason,
        ]);
    }

    private function queueRequestEmail(EventSubstituteRequest $request): bool
    {
        $request->loadMissing('teacher');

        if (! $request->teacher instanceof User) {
            return false;
        }

        $payload = $this->content->request($request);

        return $this->managedEmail->handle(
            recipients: $request->teacher->email,
            emailTypeKey: 'event-substitute-request',
            tokens: $payload['tokens'],
            slots: $payload['slots'],
        );
    }

    private function queueRemovedEmail(EventSubstituteRequest $request, string $reason): bool
    {
        $request->loadMissing(['event', 'teacher']);

        if (! $request->teacher instanceof User || $request->event->isCompletedAt()) {
            return false;
        }

        $payload = $this->content->removed($request, $reason);

        return $this->managedEmail->handle(
            recipients: $request->teacher->email,
            emailTypeKey: 'event-substitute-removed',
            tokens: $payload['tokens'],
            slots: $payload['slots'],
        );
    }

    private function requiredText(string $value): string
    {
        $value = (string) str($value)->squish();

        if ($value === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        return $value;
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) str($value)->squish();

        return $value !== '' ? $value : null;
    }
}
