<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    public function setStudentAttendance(Event $event, Student $student, bool $attended): EventAttendee
    {
        return $this->attendanceFor($event, $student, [
            'attended' => $attended,
        ]);
    }

    public function setStudentAttendanceNotes(Event $event, Student $student, ?string $notes): EventAttendee
    {
        return $this->attendanceFor($event, $student, [
            'notes' => filled($notes) ? $notes : null,
        ]);
    }

    public function studentAttended(Event $event, Student $student): bool
    {
        return EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('attendee_type', $student->getMorphClass())
            ->where('attendee_id', $student->id)
            ->where('attended', true)
            ->exists();
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

    public function recordStudentAttended(Event $event, Model $record): bool
    {
        $student = $this->studentForAttendanceRecord($record);

        return $student instanceof Student && $this->studentAttended($event, $student);
    }

    public function setRecordStudentAttendance(Event $event, Model $record, mixed $state): bool
    {
        $student = $this->studentForAttendanceRecord($record);

        if (! $student instanceof Student) {
            return false;
        }

        $attended = (bool) $state;

        $this->setStudentAttendance($event, $student, $attended);

        return $attended;
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
     * @param  array{attended?: bool, notes?: string|null}  $values
     */
    private function attendanceFor(Event $event, Student $student, array $values): EventAttendee
    {
        return EventAttendee::query()->updateOrCreate([
            'event_id' => $event->id,
            'attendee_type' => $student->getMorphClass(),
            'attendee_id' => $student->id,
        ], $values);
    }
}
