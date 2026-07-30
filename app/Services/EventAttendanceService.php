<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class EventAttendanceService
{
    /**
     * @return Builder<Enrollment>
     */
    public function courseRosterQuery(int $courseId): Builder
    {
        return Enrollment::query()
            ->with('student')
            ->where('course_id', $courseId)
            ->whereNotNull('student_id')
            ->whereHas('student')
            ->orderBy(
                Enrollment::query()
                    ->select('first_name')
                    ->from('students')
                    ->whereColumn('students.id', 'enrollments.student_id')
                    ->limit(1)
            )
            ->orderBy(
                Enrollment::query()
                    ->select('last_name')
                    ->from('students')
                    ->whereColumn('students.id', 'enrollments.student_id')
                    ->limit(1)
            );
    }

    /**
     * @return Builder<Model>
     */
    public function eventRosterQuery(Event $event): Builder
    {
        if ($event->course_id !== null) {
            /** @var Builder<Model> $query */
            $query = $this->courseRosterQuery($event->course_id);

            return $query;
        }

        /** @var Builder<Model> $query */
        $query = EventAttendee::query()
            ->with('attendee')
            ->where('event_id', $event->id)
            ->where('attendee_type', (new Student)->getMorphClass());

        return $query;
    }

    public function setStudentAttendanceStatus(
        Event $event,
        Student $student,
        ?AttendanceStatus $status,
    ): EventAttendee {
        return $this->attendanceFor($event, $student, [
            'status' => $status,
        ]);
    }

    public function setStudentAttendanceNotes(Event $event, Student $student, ?string $notes): EventAttendee
    {
        return $this->attendanceFor($event, $student, [
            'notes' => filled($notes) ? $notes : null,
        ]);
    }

    public function studentAttendanceStatus(Event $event, Student $student): ?AttendanceStatus
    {
        $status = EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('attendee_type', $student->getMorphClass())
            ->where('attendee_id', $student->id)
            ->value('status');

        if ($status instanceof AttendanceStatus) {
            return $status;
        }

        return is_string($status) ? AttendanceStatus::tryFrom($status) : null;
    }

    public function studentNotes(Event $event, Student $student): ?string
    {
        $notes = EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('attendee_type', $student->getMorphClass())
            ->where('attendee_id', $student->id)
            ->value('notes');

        return is_string($notes) ? $notes : null;
    }

    public function studentForAttendanceRecord(Model $record): ?Student
    {
        if ($record instanceof Enrollment) {
            return $record->student;
        }

        if ($record instanceof EventAttendee && $record->attendee instanceof Student) {
            return $record->attendee;
        }

        return null;
    }

    public function recordStudentName(Model $record): string
    {
        $student = $this->studentForAttendanceRecord($record);

        return $student instanceof Student ? $student->fullName : 'Unknown Student';
    }

    public function recordStudentAttendanceStatus(Event $event, Model $record): ?string
    {
        $student = $this->studentForAttendanceRecord($record);

        return $student instanceof Student
            ? $this->studentAttendanceStatus($event, $student)?->value
            : null;
    }

    public function setRecordStudentAttendanceStatus(Event $event, Model $record, mixed $state): ?string
    {
        $student = $this->studentForAttendanceRecord($record);

        if (! $student instanceof Student) {
            return null;
        }

        $status = is_string($state) ? AttendanceStatus::tryFrom($state) : null;

        $this->setStudentAttendanceStatus($event, $student, $status);

        return $status?->value;
    }

    public function recordStudentNotes(Event $event, Model $record): ?string
    {
        $student = $this->studentForAttendanceRecord($record);

        return $student instanceof Student ? $this->studentNotes($event, $student) : null;
    }

    public function setRecordStudentNotes(Event $event, Model $record, mixed $state): ?string
    {
        $student = $this->studentForAttendanceRecord($record);

        if (! $student instanceof Student) {
            return null;
        }

        $notes = is_string($state) ? $state : null;

        $this->setStudentAttendanceNotes($event, $student, $notes);

        return filled($notes) ? $notes : null;
    }

    /**
     * @param  array{status?: AttendanceStatus|null, notes?: string|null}  $values
     */
    private function attendanceFor(Event $event, Student $student, array $values): EventAttendee
    {
        Gate::authorize('updateAttendance', $event);

        $attendance = EventAttendee::query()->firstOrNew([
            'event_id' => $event->id,
            'attendee_type' => $student->getMorphClass(),
            'attendee_id' => $student->id,
        ]);

        $attendance->forceFill($values);

        if (
            $event->course_id !== null
            && $attendance->status === null
            && blank($attendance->notes)
        ) {
            if ($attendance->exists) {
                $attendance->delete();
            }

            return $attendance;
        }

        $attendance->save();

        return $attendance;
    }
}
