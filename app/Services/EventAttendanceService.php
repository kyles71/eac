<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\User;
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
            ->whereIn('attendee_type', [
                (new Student)->getMorphClass(),
                (new User)->getMorphClass(),
            ]);

        return $query;
    }

    public function setStudentAttendanceStatus(
        Event $event,
        Student $student,
        ?AttendanceStatus $status,
    ): EventAttendee {
        return $this->setAttendanceStatus($event, $student, $status);
    }

    public function setStudentAttendanceNotes(Event $event, Student $student, ?string $notes): EventAttendee
    {
        return $this->setAttendanceNotes($event, $student, $notes);
    }

    public function studentAttendanceStatus(Event $event, Student $student): ?AttendanceStatus
    {
        return $this->attendanceStatus($event, $student);
    }

    public function studentNotes(Event $event, Student $student): ?string
    {
        return $this->attendanceNotes($event, $student);
    }

    public function studentForAttendanceRecord(Model $record): ?Student
    {
        $attendee = $this->attendeeForAttendanceRecord($record);

        return $attendee instanceof Student ? $attendee : null;
    }

    public function attendeeForAttendanceRecord(Model $record): Student|User|null
    {
        if ($record instanceof Enrollment) {
            return $record->student;
        }

        if (
            $record instanceof EventAttendee
            && ($record->attendee instanceof Student || $record->attendee instanceof User)
        ) {
            return $record->attendee;
        }

        return null;
    }

    public function recordAttendeeName(Model $record): string
    {
        $attendee = $this->attendeeForAttendanceRecord($record);

        return $attendee instanceof Student || $attendee instanceof User
            ? $attendee->fullName
            : 'Unknown Attendee';
    }

    public function recordAttendanceStatus(Event $event, Model $record): ?string
    {
        $attendee = $this->attendeeForAttendanceRecord($record);

        return $attendee instanceof Student || $attendee instanceof User
            ? $this->attendanceStatus($event, $attendee)?->value
            : null;
    }

    public function setRecordAttendanceStatus(Event $event, Model $record, mixed $state): ?string
    {
        $attendee = $this->attendeeForAttendanceRecord($record);

        if (! $attendee instanceof Student && ! $attendee instanceof User) {
            return null;
        }

        $status = is_string($state) ? AttendanceStatus::tryFrom($state) : null;

        $this->setAttendanceStatus($event, $attendee, $status);

        return $status?->value;
    }

    public function recordAttendanceNotes(Event $event, Model $record): ?string
    {
        $attendee = $this->attendeeForAttendanceRecord($record);

        return $attendee instanceof Student || $attendee instanceof User
            ? $this->attendanceNotes($event, $attendee)
            : null;
    }

    public function setRecordAttendanceNotes(Event $event, Model $record, mixed $state): ?string
    {
        $attendee = $this->attendeeForAttendanceRecord($record);

        if (! $attendee instanceof Student && ! $attendee instanceof User) {
            return null;
        }

        $notes = is_string($state) ? $state : null;

        $this->setAttendanceNotes($event, $attendee, $notes);

        return filled($notes) ? $notes : null;
    }

    private function setAttendanceStatus(
        Event $event,
        Student|User $attendee,
        ?AttendanceStatus $status,
    ): EventAttendee {
        return $this->attendanceFor($event, $attendee, [
            'status' => $status,
        ]);
    }

    private function setAttendanceNotes(Event $event, Student|User $attendee, ?string $notes): EventAttendee
    {
        return $this->attendanceFor($event, $attendee, [
            'notes' => filled($notes) ? $notes : null,
        ]);
    }

    private function attendanceStatus(Event $event, Student|User $attendee): ?AttendanceStatus
    {
        $status = EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('attendee_type', $attendee->getMorphClass())
            ->where('attendee_id', $attendee->id)
            ->value('status');

        if ($status instanceof AttendanceStatus) {
            return $status;
        }

        return is_string($status) ? AttendanceStatus::tryFrom($status) : null;
    }

    private function attendanceNotes(Event $event, Student|User $attendee): ?string
    {
        $notes = EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('attendee_type', $attendee->getMorphClass())
            ->where('attendee_id', $attendee->id)
            ->value('notes');

        return is_string($notes) ? $notes : null;
    }

    /**
     * @param  array{status?: AttendanceStatus|null, notes?: string|null}  $values
     */
    private function attendanceFor(Event $event, Student|User $attendee, array $values): EventAttendee
    {
        Gate::authorize('updateAttendance', $event);

        $attendance = EventAttendee::query()->firstOrNew([
            'event_id' => $event->id,
            'attendee_type' => $attendee->getMorphClass(),
            'attendee_id' => $attendee->id,
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
