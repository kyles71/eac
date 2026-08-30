<?php

declare(strict_types=1);

namespace App\Actions\Enrollments;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use InvalidArgumentException;

final readonly class AssignStudentToEnrollmentAction
{
    public function handle(Enrollment $enrollment, Student $student, User $user): void
    {
        if ($enrollment->user_id !== $user->id) {
            throw new InvalidArgumentException('This enrollment does not belong to your account.');
        }

        if ($student->user_id !== $user->id) {
            throw new InvalidArgumentException('This student does not belong to your account.');
        }

        if ($enrollment->isRecurringPrivateLesson()) {
            throw new InvalidArgumentException('The dancer assigned to a recurring private lesson cannot be changed.');
        }

        if ($enrollment->student_id !== null && ! $this->canChangeAssignedStudent($enrollment)) {
            throw new InvalidArgumentException('This enrollment is too close to the course start date to change online.');
        }

        $enrollment->update([
            'student_id' => $student->id,
        ]);
    }

    public function canChangeAssignedStudent(Enrollment $enrollment): bool
    {
        if ($enrollment->isRecurringPrivateLesson()) {
            return false;
        }

        if ($enrollment->student_id === null) {
            return true;
        }

        $enrollment->loadMissing('course');

        if ($enrollment->course === null) {
            return false;
        }

        $cutoffDays = (int) config('app.enrollment_unassign_cutoff_days', 7);

        return $enrollment->course->firstMeetingStartsAt()?->gte(now()->addDays($cutoffDays)) ?? false;
    }
}
