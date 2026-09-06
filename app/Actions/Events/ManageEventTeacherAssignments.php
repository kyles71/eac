<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\CourseTeacherAssignmentStrategy;
use App\Enums\EventTeacherAssignmentMode;
use App\Models\Course;
use App\Models\Event;
use App\Models\User;
use App\Services\TeacherScheduleConflictService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ManageEventTeacherAssignments
{
    public function __construct(private TeacherScheduleConflictService $conflicts) {}

    /** @param list<int> $teacherIds */
    public function assignCustom(Event $event, array $teacherIds): Event
    {
        return DB::transaction(function () use ($event, $teacherIds): Event {
            $lockedEvent = $this->lockedEvent($event);
            $teacherIds = $this->validTeacherIds($teacherIds);

            if ($lockedEvent->course_id !== null && $teacherIds === []) {
                throw new InvalidArgumentException('Custom course events require at least one teacher.');
            }

            $this->ensureCoveredTeachersRemainAssigned($lockedEvent, $teacherIds);
            $this->ensureNoConflicts($lockedEvent, $teacherIds);

            $lockedEvent->update([
                'teacher_assignment_mode' => EventTeacherAssignmentMode::Custom,
            ]);
            $lockedEvent->teachers()->sync($teacherIds);

            return $lockedEvent->refresh()->load('teachers');
        });
    }

    public function useCourseDefaults(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            $lockedEvent = $this->lockedEvent($event);
            $course = $lockedEvent->course;

            if (! $course instanceof Course) {
                throw new InvalidArgumentException('Standalone events cannot use course teacher defaults.');
            }

            if ($lockedEvent->substituteCoverages()->active()->exists()) {
                throw new DomainException('Resolve active substitute coverage before restoring course teacher defaults.');
            }

            $lockedEvent->update([
                'teacher_assignment_mode' => EventTeacherAssignmentMode::CourseDefaults,
                'teacher_rotation_sequence' => $lockedEvent->teacher_rotation_sequence
                    ?? $this->nextRotationSequence($course),
            ]);
            $this->syncDefaultAssignment($lockedEvent, $course);

            return $lockedEvent->refresh()->load('teachers');
        });
    }

    public function initializeCourseEvent(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            $lockedEvent = $this->lockedEvent($event);
            $course = $lockedEvent->course;

            if (! $course instanceof Course) {
                throw new InvalidArgumentException('The event must belong to a course.');
            }

            $lockedEvent->update([
                'teacher_assignment_mode' => EventTeacherAssignmentMode::CourseDefaults,
                'teacher_rotation_sequence' => $lockedEvent->teacher_rotation_sequence
                    ?? $this->nextRotationSequence($course, $lockedEvent),
            ]);
            $this->syncDefaultAssignment($lockedEvent, $course);

            return $lockedEvent->refresh()->load('teachers');
        });
    }

    public function pinForSubstituteCoverage(Event $event): Event
    {
        if ($event->teacher_assignment_mode === EventTeacherAssignmentMode::Custom) {
            return $event;
        }

        $event->update(['teacher_assignment_mode' => EventTeacherAssignmentMode::Custom]);

        return $event;
    }

    /** @param list<int> $orderedTeacherIds */
    public function updateCourseRoster(
        Course $course,
        array $orderedTeacherIds,
        CourseTeacherAssignmentStrategy $strategy,
    ): Course {
        return DB::transaction(function () use ($course, $orderedTeacherIds, $strategy): Course {
            $teacherIds = $this->validTeacherIds($orderedTeacherIds);

            if ($strategy === CourseTeacherAssignmentStrategy::RotateTeachers && count($teacherIds) < 2) {
                throw new InvalidArgumentException('A teacher rotation requires at least two teachers.');
            }

            $course->update(['teacher_assignment_strategy' => $strategy]);
            $course->teachers()->sync(collect($teacherIds)
                ->mapWithKeys(fn (int $teacherId, int $position): array => [
                    $teacherId => ['rotation_position' => $position + 1],
                ])
                ->all());
            $course->unsetRelation('teachers');
            $this->synchronizeFutureCourseEvents($course);

            return $course->refresh()->load('teachers');
        });
    }

    public function synchronizeFutureCourseEvents(Course $course): void
    {
        $course->load('teachers');

        $course->events()
            ->where('teacher_assignment_mode', EventTeacherAssignmentMode::CourseDefaults)
            ->where('start_time', '>', now())
            ->orderBy('teacher_rotation_sequence')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->each(fn (Event $event) => $this->syncDefaultAssignment($event, $course));
    }

    /** @return list<int> */
    public function defaultTeacherIds(Event $event, Course $course): array
    {
        $course->loadMissing('teachers');
        $teacherIds = $course->teachers
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($course->teacher_assignment_strategy !== CourseTeacherAssignmentStrategy::RotateTeachers
            || $teacherIds->isEmpty()) {
            return $teacherIds->all();
        }

        $sequence = max(1, (int) $event->teacher_rotation_sequence);

        return [(int) $teacherIds[($sequence - 1) % $teacherIds->count()]];
    }

    private function syncDefaultAssignment(Event $event, Course $course): void
    {
        $teacherIds = $this->defaultTeacherIds($event, $course);
        $this->ensureNoConflicts($event, $teacherIds);
        $event->teachers()->sync($teacherIds);
    }

    /** @param list<int> $teacherIds */
    private function ensureNoConflicts(Event $event, array $teacherIds): void
    {
        $assignedTeacherIds = collect($teacherIds)
            ->merge($event->activeSubstituteCoverages()
                ->whereNotNull('substitute_teacher_id')
                ->pluck('substitute_teacher_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        foreach (User::query()->whereKey($assignedTeacherIds)->get() as $teacher) {
            $conflict = $this->conflicts->conflictingEvent($event, $teacher);

            if ($conflict instanceof Event) {
                throw new DomainException(
                    "{$teacher->fullName} is already assigned to the overlapping event \"{$conflict->name}\".",
                );
            }
        }
    }

    /** @param list<int> $teacherIds */
    private function ensureCoveredTeachersRemainAssigned(Event $event, array $teacherIds): void
    {
        $coveredTeacherIds = $event->substituteCoverages()
            ->active()
            ->whereNotNull('covered_teacher_id')
            ->pluck('covered_teacher_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (array_diff($coveredTeacherIds, $teacherIds) !== []) {
            throw new DomainException('Resolve substitute coverage before removing the covered teacher.');
        }
    }

    /**
     * @param  array<int, mixed>  $teacherIds
     * @return list<int>
     */
    private function validTeacherIds(array $teacherIds): array
    {
        $normalizedIds = collect($teacherIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $validIds = User::query()
            ->whereKey($normalizedIds)
            ->whereHas('roles', fn (Builder $query): Builder => $query
                ->whereIn('name', ['teacher', 'owner', 'super_admin']))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($normalizedIds->diff($validIds)->isNotEmpty()) {
            throw new InvalidArgumentException('Only registered staff may be assigned to teach an event.');
        }

        return $normalizedIds->all();
    }

    private function nextRotationSequence(Course $course, ?Event $excluding = null): int
    {
        return ((int) $course->events()
            ->when($excluding instanceof Event, fn (Builder $query): Builder => $query->whereKeyNot($excluding->id))
            ->max('teacher_rotation_sequence')) + 1;
    }

    private function lockedEvent(Event $event): Event
    {
        $lockedEvent = Event::query()
            ->with(['course.teachers'])
            ->lockForUpdate()
            ->find($event->getKey());

        if (! $lockedEvent instanceof Event) {
            throw new InvalidArgumentException('The event could not be found.');
        }

        return $lockedEvent;
    }
}
