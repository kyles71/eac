<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Events\ManageEventTeacherAssignments;
use App\Enums\EventTeacherAssignmentMode;
use App\Enums\ScheduleFrequency;
use App\Models\Course;
use App\Models\Event;
use App\Models\User;
use App\Support\ApplicationDateTime;
use App\Support\Events\TeacherScheduleConflict;
use App\Support\Events\TeacherScheduleProposal;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Throwable;

final readonly class EventScheduleConflictReviewService
{
    public function __construct(
        private TeacherScheduleConflictService $conflicts,
        private ManageEventTeacherAssignments $assignments,
        private HolidayConflictService $holidayConflicts,
        private ScheduleRecurrenceService $recurrence,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @return Collection<int, TeacherScheduleConflict>
     */
    public function conflictsForFormState(
        array $state,
        ?Event $record = null,
        ?int $fixedCourseId = null,
        bool $includeRecurrences = false,
    ): Collection {
        $intervals = $this->proposedIntervals($state, $fixedCourseId, $includeRecurrences);

        if ($intervals === []) {
            return collect();
        }

        $course = $this->course($state, $record, $fixedCourseId);
        $mode = $this->assignmentMode($state, $record, $course);
        $customTeacherIds = $this->integerIds($state['teacher_ids'] ?? []);
        $nextRotationSequence = $course instanceof Course
            ? ((int) $course->events()->when(
                $record instanceof Event,
                fn ($query) => $query->whereKeyNot($record->id),
            )->max('teacher_rotation_sequence')) + 1
            : null;
        $substituteTeacherIds = $record instanceof Event
            ? $record->activeSubstituteCoverages()
                ->whereNotNull('substitute_teacher_id')
                ->pluck('substitute_teacher_id')
            : collect();
        $proposals = collect($intervals)
            ->map(function (array $interval, int $index) use (
                $course,
                $customTeacherIds,
                $mode,
                $nextRotationSequence,
                $record,
                $state,
                $substituteTeacherIds,
            ): array {
                $name = $state['name'] ?? $record?->name;
                $proposal = new Event([
                    'name' => is_string($name) ? $name : 'Proposed event',
                    'course_id' => $course?->id,
                    'start_time' => $interval['start_time'],
                    'end_time' => $interval['end_time'],
                    'teacher_assignment_mode' => $mode,
                    'teacher_rotation_sequence' => $index === 0 && $record instanceof Event
                        ? ($record->teacher_rotation_sequence ?? $nextRotationSequence)
                        : ($nextRotationSequence === null ? null : $nextRotationSequence + $index),
                ]);

                if ($index === 0 && $record instanceof Event) {
                    $proposal->setAttribute($proposal->getKeyName(), $record->getKey());
                    $proposal->exists = true;
                }

                $teacherIds = $course instanceof Course && $mode === EventTeacherAssignmentMode::CourseDefaults
                    ? $this->assignments->defaultTeacherIds($proposal, $course)
                    : $customTeacherIds;

                if ($index === 0 && $record instanceof Event) {
                    $teacherIds = collect($teacherIds)
                        ->merge($substituteTeacherIds)
                        ->map(fn (mixed $id): int => (int) $id)
                        ->unique()
                        ->values()
                        ->all();
                }

                return [
                    'event' => $proposal,
                    'teacher_ids' => $teacherIds,
                ];
            });
        $teacherIds = $proposals
            ->flatMap(fn (array $proposal): array => $proposal['teacher_ids'])
            ->unique()
            ->values()
            ->all();
        $teachers = User::query()
            ->whereKey($teacherIds)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
        $scheduleProposals = $proposals->map(
            fn (array $proposal): TeacherScheduleProposal => new TeacherScheduleProposal(
                event: $proposal['event'],
                teachers: $teachers
                    ->whereIn('id', $proposal['teacher_ids'])
                    ->values()
                    ->all(),
            ),
        );

        return $this->conflicts->conflictsForProposals($scheduleProposals)
            ->unique(fn (TeacherScheduleConflict $conflict): string => $conflict->fingerprintPart())
            ->values();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{start_time: CarbonInterface, end_time: CarbonInterface}>
     */
    private function proposedIntervals(array $state, ?int $fixedCourseId, bool $includeRecurrences): array
    {
        try {
            $startsAt = filled($state['start_time'] ?? null)
                ? ApplicationDateTime::tryFromStorage($state['start_time'])
                : null;
            $endsAt = filled($state['end_time'] ?? null)
                ? ApplicationDateTime::tryFromStorage($state['end_time'])
                : null;
        } catch (Throwable) {
            return [];
        }

        if (! $startsAt instanceof CarbonInterface
            || ! $endsAt instanceof CarbonInterface
            || $endsAt->lte($startsAt)) {
            return [];
        }

        $intervals = [[
            'start_time' => $startsAt,
            'end_time' => $endsAt,
        ]];
        $frequency = $this->frequency($state['repeat_frequency'] ?? null);

        if (! $includeRecurrences || ! $frequency instanceof ScheduleFrequency) {
            return $intervals;
        }

        try {
            $repeatThroughValue = $state['repeat_through'] ?? null;
            $repeatThrough = filled($repeatThroughValue)
                && (is_string($repeatThroughValue) || $repeatThroughValue instanceof DateTimeInterface)
                    ? ApplicationDateTime::endOfDisplayDay($repeatThroughValue)
                    : null;
        } catch (Throwable) {
            return $intervals;
        }

        if (! $repeatThrough instanceof CarbonInterface) {
            return $intervals;
        }

        $courseId = $fixedCourseId ?? (is_numeric($state['course_id'] ?? null) ? (int) $state['course_id'] : null);
        $recurringIntervals = $this->recurrence->recurringIntervals(
            startsAt: $startsAt,
            endsAt: $endsAt,
            repeatThrough: $repeatThrough,
            frequency: $frequency,
        );
        $recurringIntervals = $this->holidayConflicts->withoutConflicts(
            $recurringIntervals,
            $courseId,
        );

        return [
            ...$intervals,
            ...collect($recurringIntervals)
                ->filter(fn (array $interval): bool => $interval['end_time'] instanceof CarbonInterface)
                ->map(fn (array $interval): array => [
                    'start_time' => $interval['start_time'],
                    'end_time' => $interval['end_time'],
                ])
                ->all(),
        ];
    }

    /** @param array<string, mixed> $state */
    private function course(array $state, ?Event $record, ?int $fixedCourseId): ?Course
    {
        if ($fixedCourseId !== null) {
            return Course::query()->find($fixedCourseId);
        }

        if (array_key_exists('course_id', $state)) {
            $courseId = $state['course_id'];

            return is_numeric($courseId) ? Course::query()->find((int) $courseId) : null;
        }

        return $record?->course;
    }

    /** @param array<string, mixed> $state */
    private function assignmentMode(array $state, ?Event $record, ?Course $course): EventTeacherAssignmentMode
    {
        $mode = $state['teacher_assignment_mode'] ?? $record?->teacher_assignment_mode;

        if ($mode instanceof EventTeacherAssignmentMode) {
            return $mode;
        }

        if (is_string($mode) && ($normalized = EventTeacherAssignmentMode::tryFrom($mode)) instanceof EventTeacherAssignmentMode) {
            return $normalized;
        }

        return $course instanceof Course
            ? EventTeacherAssignmentMode::CourseDefaults
            : EventTeacherAssignmentMode::Custom;
    }

    private function frequency(mixed $frequency): ?ScheduleFrequency
    {
        if ($frequency instanceof ScheduleFrequency) {
            return $frequency;
        }

        return is_string($frequency) ? ScheduleFrequency::tryFrom($frequency) : null;
    }

    /** @return list<int> */
    private function integerIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
