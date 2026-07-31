<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentWaiver;
use Illuminate\Database\Eloquent\Builder;

final class StudentProfileService
{
    /**
     * @var array<int, StudentWaiver|null>
     */
    private array $medicalWaivers = [];

    public function medicalWaiver(Student $student): ?StudentWaiver
    {
        if (array_key_exists($student->id, $this->medicalWaivers)) {
            return $this->medicalWaivers[$student->id];
        }

        $response = $student->currentMedicalWaiver()?->responseable;

        return $this->medicalWaivers[$student->id] = $response instanceof StudentWaiver
            ? $response
            : null;
    }

    /**
     * @return list<array{
     *     course: string,
     *     present: int,
     *     late: int,
     *     excused_absence: int,
     *     unexcused_absence: int,
     *     not_recorded: int,
     *     total_events: int
     * }>
     */
    public function attendanceTotals(Student $student): array
    {
        $statusCounts = collect(AttendanceStatus::cases())
            ->mapWithKeys(fn (AttendanceStatus $status): array => [
                "events as {$status->value}_count" => fn (Builder $query): Builder => $this
                    ->applyAttendanceStatusConstraint($query, $student, $status),
            ])
            ->all();

        return Course::query()
            ->select(['courses.id', 'courses.name'])
            ->whereHas(
                'enrollments',
                fn (Builder $query): Builder => $query->where('student_id', $student->id),
            )
            ->withCount([
                'events as total_events_count' => fn (Builder $query): Builder => $this
                    ->applyOccurredEventConstraint($query),
                ...$statusCounts,
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(function (Course $course): array {
                $present = (int) $course->getAttribute('present_count');
                $late = (int) $course->getAttribute('late_count');
                $excusedAbsence = (int) $course->getAttribute('excused_absence_count');
                $unexcusedAbsence = (int) $course->getAttribute('unexcused_absence_count');
                $totalEvents = (int) $course->getAttribute('total_events_count');
                $recordedEvents = $present + $late + $excusedAbsence + $unexcusedAbsence;

                return [
                    'course' => (string) $course->name,
                    'present' => $present,
                    'late' => $late,
                    'excused_absence' => $excusedAbsence,
                    'unexcused_absence' => $unexcusedAbsence,
                    'not_recorded' => max(0, $totalEvents - $recordedEvents),
                    'total_events' => $totalEvents,
                ];
            })
            ->values()
            ->all();
    }

    private function applyAttendanceStatusConstraint(
        Builder $query,
        Student $student,
        AttendanceStatus $status,
    ): Builder {
        return $this->applyOccurredEventConstraint($query)
            ->whereHas('attendees', fn (Builder $query): Builder => $query
                ->where('attendee_type', $student->getMorphClass())
                ->where('attendee_id', $student->id)
                ->where('status', $status));
    }

    private function applyOccurredEventConstraint(Builder $query): Builder
    {
        return $query
            ->whereNull('cancelled_at')
            ->whereNotNull('start_time')
            ->where('start_time', '<=', now());
    }
}
