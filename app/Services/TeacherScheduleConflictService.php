<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\User;
use App\Support\Events\TeacherScheduleConflict;
use App\Support\Events\TeacherScheduleProposal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

final readonly class TeacherScheduleConflictService
{
    /** @return Collection<int, User> */
    public function availableSubstituteTeachers(Event $event): Collection
    {
        return User::query()
            ->role('teacher')
            ->whereDoesntHave('eventTeacherAssignments', fn (Builder $query): Builder => $query
                ->where('event_id', $event->id))
            ->whereDoesntHave('substituteCoverages', fn (Builder $query): Builder => $query
                ->where('event_id', $event->id)
                ->whereNotNull('needed_at')
                ->whereNull('closed_at'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->filter(fn (User $teacher): bool => ! $this->conflictingEvent($event, $teacher) instanceof Event)
            ->values();
    }

    public function isAvailableToSubstitute(Event $event, User $teacher): bool
    {
        return $teacher->hasRole('teacher')
            && ! $event->isAssignedTeacher($teacher)
            && ! $event->activeSubstituteCoverages()
                ->where('substitute_teacher_id', $teacher->id)
                ->exists()
            && ! $this->conflictingEvent($event, $teacher) instanceof Event;
    }

    public function conflictingEvent(Event $event, User $teacher): ?Event
    {
        return $this->conflictingEvents($event, $teacher)->first();
    }

    /** @return Collection<int, Event> */
    public function conflictingEvents(Event $event, User $teacher): Collection
    {
        if ($event->start_time === null || $event->end_time === null) {
            return new Collection;
        }

        $userMorphClass = $teacher->getMorphClass();

        return Event::query()
            ->whereKeyNot($event->id)
            ->whereNull('cancelled_at')
            ->overlapping($event->start_time, $event->end_time)
            ->where(function (Builder $query) use ($teacher, $userMorphClass): void {
                $query
                    ->where(function (Builder $query) use ($teacher): void {
                        $query
                            ->whereHas(
                                'teacherAssignments',
                                fn (Builder $query): Builder => $query->where('teacher_id', $teacher->id),
                            )
                            ->whereDoesntHave('substituteCoverages', fn (Builder $query): Builder => $query
                                ->whereNotNull('needed_at')
                                ->whereNull('closed_at')
                                ->where('covered_teacher_id', $teacher->id));
                    })
                    ->orWhereHas('substituteCoverages', fn (Builder $query): Builder => $query
                        ->whereNotNull('needed_at')
                        ->whereNull('closed_at')
                        ->where('substitute_teacher_id', $teacher->id))
                    ->orWhere(function (Builder $query) use ($teacher, $userMorphClass): void {
                        $query
                            ->whereDoesntHave(
                                'excludedUsers',
                                fn (Builder $query): Builder => $query->whereKey($teacher->id),
                            )
                            ->whereHas('attendees', fn (Builder $query): Builder => $query
                                ->where('attendee_type', $userMorphClass)
                                ->where('attendee_id', $teacher->id));
                    });
            })
            ->oldest('start_time')
            ->oldest('id')
            ->get();
    }

    /**
     * @param  iterable<int, User>  $teachers
     * @return SupportCollection<int, TeacherScheduleConflict>
     */
    public function conflicts(Event $event, iterable $teachers): SupportCollection
    {
        return $this->conflictsForProposals([
            new TeacherScheduleProposal($event, collect($teachers)->values()->all()),
        ]);
    }

    /**
     * @param  iterable<int, TeacherScheduleProposal>  $proposals
     * @return SupportCollection<int, TeacherScheduleConflict>
     */
    public function conflictsForProposals(iterable $proposals): SupportCollection
    {
        $proposals = collect($proposals)->values();
        $teachers = $proposals
            ->flatMap(fn (TeacherScheduleProposal $proposal): array => $proposal->teachers)
            ->unique(fn (User $teacher): int => $teacher->id)
            ->values();

        if ($proposals->isEmpty() || $teachers->isEmpty()) {
            return collect();
        }

        $candidateEvents = $this->candidateEvents($proposals, $teachers);

        return $proposals
            ->flatMap(fn (TeacherScheduleProposal $proposal): SupportCollection => collect($proposal->teachers)
                ->flatMap(fn (User $teacher): SupportCollection => $candidateEvents
                    ->filter(fn (Event $candidate): bool => $this->isConflict(
                        $proposal->event,
                        $candidate,
                        $teacher,
                    ))
                    ->map(fn (Event $conflictingEvent): TeacherScheduleConflict => new TeacherScheduleConflict(
                        teacher: $teacher,
                        proposedEvent: $proposal->event,
                        conflictingEvent: $conflictingEvent,
                    ))))
            ->values();
    }

    /**
     * @param  SupportCollection<int, TeacherScheduleProposal>  $proposals
     * @param  SupportCollection<int, User>  $teachers
     * @return Collection<int, Event>
     */
    private function candidateEvents(SupportCollection $proposals, SupportCollection $teachers): Collection
    {
        $proposedEvents = $proposals->map(
            fn (TeacherScheduleProposal $proposal): Event => $proposal->event,
        );
        $startsAt = $proposedEvents->min(
            fn (Event $event): ?CarbonInterface => $event->start_time,
        );
        $endsAt = $proposedEvents->max(
            fn (Event $event): ?CarbonInterface => $event->end_time,
        );

        if (! $startsAt instanceof CarbonInterface || ! $endsAt instanceof CarbonInterface) {
            return new Collection;
        }

        $teacherIds = $teachers->pluck('id')->all();
        $userMorphClass = (new User)->getMorphClass();
        $proposedEventIds = $proposedEvents
            ->filter(fn (Event $event): bool => $event->getKey() !== null)
            ->map(fn (Event $event): int => $event->id)
            ->values()
            ->all();

        return Event::query()
            ->when(
                $proposedEventIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('events.id', $proposedEventIds),
            )
            ->whereNull('cancelled_at')
            ->overlapping($startsAt, $endsAt)
            ->where(function (Builder $query) use ($teacherIds, $userMorphClass): void {
                $query
                    ->whereHas(
                        'teacherAssignments',
                        fn (Builder $query): Builder => $query->whereIn('teacher_id', $teacherIds),
                    )
                    ->orWhereHas(
                        'activeSubstituteCoverages',
                        fn (Builder $query): Builder => $query->whereIn('substitute_teacher_id', $teacherIds),
                    )
                    ->orWhereHas('attendees', fn (Builder $query): Builder => $query
                        ->where('attendee_type', $userMorphClass)
                        ->whereIn('attendee_id', $teacherIds));
            })
            ->with([
                'teacherAssignments' => fn ($query) => $query
                    ->whereIn('teacher_id', $teacherIds),
                'activeSubstituteCoverages' => fn ($query) => $query
                    ->where(function (Builder $query) use ($teacherIds): void {
                        $query
                            ->whereIn('covered_teacher_id', $teacherIds)
                            ->orWhereIn('substitute_teacher_id', $teacherIds);
                    }),
                'attendees' => fn ($query) => $query
                    ->where('attendee_type', $userMorphClass)
                    ->whereIn('attendee_id', $teacherIds),
                'excludedUsers' => fn ($query) => $query->whereKey($teacherIds),
                'course.recurringPrivateLesson',
            ])
            ->oldest('start_time')
            ->oldest('id')
            ->get();
    }

    private function isConflict(Event $proposedEvent, Event $candidateEvent, User $teacher): bool
    {
        if (! $this->eventsOverlap($proposedEvent, $candidateEvent)) {
            return false;
        }

        $activeCoverages = $candidateEvent->activeSubstituteCoverages;
        $isRegularTeacher = $candidateEvent->teacherAssignments
            ->contains('teacher_id', $teacher->id)
            && ! $activeCoverages->contains('covered_teacher_id', $teacher->id);
        $isSubstituteTeacher = $activeCoverages->contains('substitute_teacher_id', $teacher->id);
        $isNonExcludedAttendee = $candidateEvent->attendees
            ->contains(fn (EventAttendee $attendee): bool => $attendee->attendee_type === $teacher->getMorphClass()
                && $attendee->attendee_id === $teacher->id)
            && ! $candidateEvent->excludedUsers->contains('id', $teacher->id);

        return $isRegularTeacher || $isSubstituteTeacher || $isNonExcludedAttendee;
    }

    private function eventsOverlap(Event $proposedEvent, Event $candidateEvent): bool
    {
        $proposedStartsAt = $proposedEvent->start_time;
        $proposedEndsAt = $proposedEvent->end_time;
        $candidateStartsAt = $candidateEvent->start_time;
        $candidateEndsAt = $candidateEvent->end_time;

        if (! $proposedStartsAt instanceof CarbonInterface
            || ! $proposedEndsAt instanceof CarbonInterface
            || ! $candidateStartsAt instanceof CarbonInterface) {
            return false;
        }

        if (! $candidateEndsAt instanceof CarbonInterface) {
            return $candidateStartsAt->gte($proposedStartsAt)
                && $candidateStartsAt->lt($proposedEndsAt);
        }

        return $candidateStartsAt->lt($proposedEndsAt)
            && $candidateEndsAt->gt($proposedStartsAt);
    }
}
