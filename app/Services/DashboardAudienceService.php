<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DashboardAudience;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DashboardAudienceService
{
    /**
     * @return list<DashboardAudience>
     */
    public function audiencesFor(User $user): array
    {
        $audiences = [DashboardAudience::Eac];
        $isOwner = $user->hasAnyRole(['owner', 'super_admin']);
        $isTeacher = $isOwner || $user->hasRole('teacher');

        $hasCurrentOrFutureEnrollment = $user->enrollments()
            ->with('course.events')
            ->get()
            ->contains(fn (Model $enrollment): bool => $enrollment instanceof Enrollment
                && ! $enrollment->course->hasConcluded());

        if ($isTeacher || $hasCurrentOrFutureEnrollment) {
            $audiences[] = DashboardAudience::Semester;
        }

        if ($isTeacher) {
            $audiences[] = DashboardAudience::Teacher;
        }

        if ($isOwner) {
            $audiences[] = DashboardAudience::Owner;
        }

        usort($audiences, fn (DashboardAudience $first, DashboardAudience $second): int => $first->priority() <=> $second->priority());

        return $audiences;
    }

    public function applyVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('audience', array_map(
            fn (DashboardAudience $audience): string => $audience->value,
            $this->audiencesFor($user),
        ));
    }

    public function applyAudienceOrder(Builder $query): Builder
    {
        return $query->orderByRaw(
            "CASE audience WHEN 'Owner' THEN 1 WHEN 'Teacher' THEN 2 WHEN 'Semester' THEN 3 WHEN 'EAC' THEN 4 ELSE 5 END"
        );
    }
}
