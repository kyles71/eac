<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\User;

final readonly class SubstituteTeacherConflictService
{
    public function __construct(private TeacherScheduleConflictService $teacherConflicts) {}

    public function conflictingEvent(Event $event, User $teacher): ?Event
    {
        return $this->teacherConflicts->conflictingEvent($event, $teacher);
    }
}
