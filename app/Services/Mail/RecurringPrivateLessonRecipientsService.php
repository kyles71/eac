<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\RecurringPrivateLesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class RecurringPrivateLessonRecipientsService
{
    /** @return list<string> */
    public function all(RecurringPrivateLesson $recurringPrivateLesson): array
    {
        $recurringPrivateLesson->loadMissing(['user', 'course.teachers']);
        $emails = [];

        foreach ($this->staff($recurringPrivateLesson) as $email) {
            $this->add($emails, $email);
        }

        $this->add($emails, $recurringPrivateLesson->user->email);

        return array_values($emails);
    }

    /** @return list<string> */
    public function staff(RecurringPrivateLesson $recurringPrivateLesson): array
    {
        $recurringPrivateLesson->loadMissing('course.teachers');
        $emails = [];
        $owners = User::query()
            ->whereHas('roles', fn (Builder $query): Builder => $query->whereIn('name', ['owner', 'super_admin']))
            ->get();

        foreach ($owners->concat($recurringPrivateLesson->course->teachers) as $user) {
            $this->add($emails, $user->email);
        }

        return array_values($emails);
    }

    /** @return list<string> */
    public function householdAndTeachers(RecurringPrivateLesson $recurringPrivateLesson): array
    {
        $recurringPrivateLesson->loadMissing(['user', 'course.teachers']);
        $emails = [];

        $this->add($emails, $recurringPrivateLesson->user->email);

        foreach ($recurringPrivateLesson->course->teachers as $teacher) {
            $this->add($emails, $teacher->email);
        }

        return array_values($emails);
    }

    /** @param array<string, string> $emails */
    private function add(array &$emails, mixed $email): void
    {
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[mb_strtolower($email)] ??= $email;
        }
    }
}
