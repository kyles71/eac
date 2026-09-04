<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Mail\QueueManagedEmail;
use App\Enums\EventSubstituteRequestReason;
use App\Enums\EventSubstituteRequestStatus;
use App\Models\Event;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use App\Services\Mail\EventSubstituteContentService;
use App\Services\SubstituteTeacherConflictService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final readonly class ManageEventSubstitution
{
    public function __construct(
        private SubstituteTeacherConflictService $conflicts,
        private EventSubstituteContentService $content,
        private QueueManagedEmail $managedEmail,
    ) {}

    public function markNeeded(Event $event, User $actor): Event
    {
        return DB::transaction(function () use ($event, $actor): Event {
            $lockedEvent = $this->lockedEvent($event);
            Gate::forUser($actor)->authorize('update', $lockedEvent);
            $this->ensureRequestable($lockedEvent);

            if ($lockedEvent->substitute_needed_at === null) {
                $lockedEvent->update(['substitute_needed_at' => now()]);
            }

            return $lockedEvent;
        });
    }

    public function requestSubstitute(
        Event $event,
        User $teacher,
        User $requestedBy,
        ?string $reason = null,
        ?EventSubstituteRequestReason $reasonType = null,
    ): EventSubstituteRequest {
        return DB::transaction(function () use ($event, $teacher, $requestedBy, $reason, $reasonType): EventSubstituteRequest {
            $lockedEvent = $this->lockedEvent($event);
            Gate::forUser($requestedBy)->authorize('update', $lockedEvent);
            $this->ensureRequestable($lockedEvent);
            $this->ensureTeacher($teacher);

            if ($lockedEvent->substitute_teacher_id === $teacher->id) {
                throw new DomainException('This teacher is already the confirmed substitute.');
            }

            if ($lockedEvent->pendingSubstituteRequest() instanceof EventSubstituteRequest) {
                throw new DomainException('This event already has a pending substitute request.');
            }

            $reason = $this->cleanText($reason);

            if ($lockedEvent->substitute_teacher_id !== null && $reason === null) {
                throw new InvalidArgumentException('A replacement reason is required.');
            }

            $this->ensureNoConflict($lockedEvent, $teacher);

            $request = $lockedEvent->substituteRequests()->create([
                'teacher_id' => $teacher->id,
                'requested_by_user_id' => $requestedBy->id,
                'status' => EventSubstituteRequestStatus::Pending,
                'reason_type' => $reasonType,
                'request_reason' => $reason,
                'sick_instructor_id' => $this->sickInstructorId($lockedEvent, $reasonType),
            ]);

            $lockedEvent->update([
                'substitute_needed_at' => $lockedEvent->substitute_needed_at ?? now(),
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

            if ($lockedRequest->teacher_id !== $teacher->id) {
                throw new DomainException('This substitute request belongs to another teacher.');
            }

            if (! $lockedRequest->isPending()) {
                throw new DomainException('This substitute request has already been answered.');
            }

            if (! $event->canAcceptSubstituteRequestAt()) {
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
            $this->ensureNoConflict($event, $teacher);
            $outgoingRequest = $event->currentSubstituteRequest();

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

            $event->update([
                'substitute_teacher_id' => $teacher->id,
                'substitute_needed_at' => $event->substitute_needed_at ?? now(),
            ]);

            if ($outgoingRequest instanceof EventSubstituteRequest && $outgoingRequest->teacher_id !== $teacher->id) {
                $this->queueRemovedEmail(
                    $outgoingRequest,
                    $lockedRequest->request_reason ?? 'A replacement substitute accepted the event.',
                );
            }

            return $lockedRequest;
        });
    }

    public function withdrawPending(Event $event, User $actor, string $reason): EventSubstituteRequest
    {
        return DB::transaction(function () use ($event, $actor, $reason): EventSubstituteRequest {
            $lockedEvent = $this->lockedEvent($event);
            Gate::forUser($actor)->authorize('update', $lockedEvent);
            $pendingRequest = $lockedEvent->pendingSubstituteRequest();

            if (! $pendingRequest instanceof EventSubstituteRequest) {
                throw new DomainException('This event does not have a pending substitute request.');
            }

            $this->closeRequest($pendingRequest, EventSubstituteRequestStatus::Withdrawn, $actor, $this->requiredText($reason));

            return $pendingRequest;
        });
    }

    public function requestRelease(Event $event, User $teacher, string $reason): EventSubstituteRequest
    {
        return DB::transaction(function () use ($event, $teacher, $reason): EventSubstituteRequest {
            $lockedEvent = $this->lockedEvent($event);

            if ($lockedEvent->substitute_teacher_id !== $teacher->id) {
                throw new DomainException('You are not the confirmed substitute for this event.');
            }

            $request = $lockedEvent->currentSubstituteRequest();

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

    public function dismissReleaseRequest(Event $event, User $actor): EventSubstituteRequest
    {
        return DB::transaction(function () use ($event, $actor): EventSubstituteRequest {
            $lockedEvent = $this->lockedEvent($event);
            Gate::forUser($actor)->authorize('update', $lockedEvent);
            $request = $lockedEvent->currentSubstituteRequest();

            if (! $request instanceof EventSubstituteRequest || ! $request->hasReleaseRequest()) {
                throw new DomainException('This event does not have a substitute release request.');
            }

            $request->update([
                'release_requested_at' => null,
                'release_reason' => null,
            ]);

            return $request;
        });
    }

    public function removeCurrent(Event $event, User $actor, string $reason, bool $keepNeeded = true): Event
    {
        return DB::transaction(function () use ($event, $actor, $reason, $keepNeeded): Event {
            $lockedEvent = $this->lockedEvent($event);
            Gate::forUser($actor)->authorize('update', $lockedEvent);

            if ($lockedEvent->isCompletedAt()) {
                throw new DomainException('Completed events require an owner historical correction.');
            }

            $reason = $this->requiredText($reason);
            $pendingRequest = $lockedEvent->pendingSubstituteRequest();

            if ($pendingRequest instanceof EventSubstituteRequest) {
                $this->closeRequest($pendingRequest, EventSubstituteRequestStatus::Withdrawn, $actor, $reason);
            }

            $currentRequest = $lockedEvent->currentSubstituteRequest();

            if ($currentRequest instanceof EventSubstituteRequest) {
                $this->closeRequest($currentRequest, EventSubstituteRequestStatus::Removed, $actor, $reason);
                $this->queueRemovedEmail($currentRequest, $reason);
            }

            $lockedEvent->update([
                'substitute_teacher_id' => null,
                'substitute_needed_at' => $keepNeeded ? ($lockedEvent->substitute_needed_at ?? now()) : null,
            ]);

            return $lockedEvent;
        });
    }

    public function recordHistoricalCorrection(Event $event, ?User $teacher, User $owner, string $reason): ?EventSubstituteRequest
    {
        return DB::transaction(function () use ($event, $teacher, $owner, $reason): ?EventSubstituteRequest {
            $lockedEvent = $this->lockedEvent($event);
            Gate::forUser($owner)->authorize('update', $lockedEvent);

            if (! $owner->hasAnyRole(['owner', 'super_admin'])) {
                throw new DomainException('Only an owner can correct a completed substitute record.');
            }

            if (! $lockedEvent->isCompletedAt()) {
                throw new DomainException('Historical substitute corrections are only available after the event ends.');
            }

            if ($teacher instanceof User) {
                $this->ensureTeacher($teacher);
            }

            $reason = $this->requiredText($reason);
            $pendingRequest = $lockedEvent->pendingSubstituteRequest();

            if ($pendingRequest instanceof EventSubstituteRequest) {
                $this->closeRequest($pendingRequest, EventSubstituteRequestStatus::Expired, $owner, $reason);
            }

            $currentRequest = $lockedEvent->currentSubstituteRequest();

            if ($currentRequest instanceof EventSubstituteRequest) {
                $status = $teacher instanceof User
                    ? EventSubstituteRequestStatus::Replaced
                    : EventSubstituteRequestStatus::Removed;
                $this->closeRequest($currentRequest, $status, $owner, $reason);
            }

            if (! $teacher instanceof User) {
                $lockedEvent->update([
                    'substitute_teacher_id' => null,
                    'substitute_needed_at' => null,
                ]);

                return null;
            }

            $request = $lockedEvent->substituteRequests()->create([
                'teacher_id' => $teacher->id,
                'requested_by_user_id' => $owner->id,
                'response_recorded_by_user_id' => $owner->id,
                'status' => EventSubstituteRequestStatus::Accepted,
                'request_reason' => $reason,
                'response_note' => 'Recorded as an owner historical correction.',
                'responded_at' => now(),
            ]);

            $lockedEvent->update([
                'substitute_teacher_id' => $teacher->id,
                'substitute_needed_at' => $lockedEvent->substitute_needed_at ?? now(),
            ]);

            return $request;
        });
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
            ->with('event')
            ->lockForUpdate()
            ->find($request->getKey());

        if (! $lockedRequest instanceof EventSubstituteRequest) {
            throw new InvalidArgumentException('The substitute request could not be found.');
        }

        return $lockedRequest;
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

    private function ensureNoConflict(Event $event, User $teacher): void
    {
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
