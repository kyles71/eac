<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final readonly class SubstituteTeacherConflictService
{
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
                    ->where('substitute_teacher_id', $teacher->id)
                    ->orWhere(function (Builder $query) use ($teacher, $userMorphClass): void {
                        $query
                            ->whereDoesntHave('excludedUsers', fn (Builder $query): Builder => $query->whereKey($teacher->id))
                            ->where(function (Builder $query) use ($teacher, $userMorphClass): void {
                                $query
                                    ->whereHas('course.teachers', fn (Builder $query): Builder => $query->whereKey($teacher->id))
                                    ->orWhereHas('attendees', fn (Builder $query): Builder => $query
                                        ->where('attendee_type', $userMorphClass)
                                        ->where('attendee_id', $teacher->id));
                            });
                    });
            })
            ->oldest('start_time')
            ->first();
    }
}
