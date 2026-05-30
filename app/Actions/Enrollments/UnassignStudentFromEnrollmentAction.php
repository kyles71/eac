<?php

declare(strict_types=1);

namespace App\Actions\Enrollments;

use App\Models\Enrollment;
use App\Models\User;
use InvalidArgumentException;

final readonly class UnassignStudentFromEnrollmentAction
{
    public function handle(Enrollment $enrollment, User $user): void
    {
        if ($enrollment->user_id !== $user->id) {
            throw new InvalidArgumentException('This enrollment does not belong to your account.');
        }

        if (! $this->canHandle($enrollment, $user)) {
            throw new InvalidArgumentException('This enrollment is too close to the course start date to remove online.');
        }

        $enrollment->update([
            'student_id' => null,
        ]);
    }

    public function canHandle(Enrollment $enrollment, User $user): bool
    {
        if ($enrollment->user_id !== $user->id || $enrollment->student_id === null) {
            return false;
        }

        $enrollment->loadMissing('course');

        if ($enrollment->course === null) {
            return false;
        }

        $cutoffDays = (int) config('app.enrollment_unassign_cutoff_days', 7);

        return $enrollment->course->start_time?->gte(now()->addDays($cutoffDays)) ?? false;
    }
}
