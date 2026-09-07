<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
        if ($event->start_time === null || $event->end_time === null) {
            return null;
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
            ->first();
    }
}
