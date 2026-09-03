<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Course;
use App\Models\CourseHoldSeat;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class EnrollmentCountService
{
    /**
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function withCounts(Builder $query): Builder
    {
        return $query->withCount($this->courseCountRelations());
    }

    /** @return array<int|string, string|(Closure(Builder): Builder)> */
    public function courseCountRelations(): array
    {
        return [
            'enrollments as reportable_enrollments_count',
            'holdSeats as reportable_holds_count' => fn (Builder $query): Builder => CourseHoldSeat::applyReservingCapacityConstraint($query),
        ];
    }

    /**
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function onlyReportableCourses(Builder $query): Builder
    {
        return $query->where('is_private', false);
    }

    public function count(Course $course): int
    {
        if ($course->is_private) {
            return 0;
        }

        return (int) $course->reportable_enrollments_count
            + $this->holdCount($course);
    }

    public function holdCount(Course $course): int
    {
        return $course->is_private
            ? 0
            : (int) $course->reportable_holds_count;
    }
}
