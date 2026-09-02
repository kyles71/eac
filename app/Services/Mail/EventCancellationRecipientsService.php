<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\User;

final readonly class EventCancellationRecipientsService
{
    /**
     * @return list<string>
     */
    public function for(Event $event): array
    {
        $excludedUserIds = $event->excludedUsers()->pluck('users.id')->all();
        $emails = [];

        $event->loadMissing('substituteTeachers');

        foreach ($event->substituteTeachers as $substituteTeacher) {
            $this->addUser($emails, $substituteTeacher);
        }

        if ($event->course_id !== null) {
            $enrollments = Enrollment::query()
                ->with(['user', 'student.user', 'student.additionalEmails'])
                ->where('course_id', $event->course_id)
                ->whereNotNull('student_id')
                ->get();

            foreach ($enrollments as $enrollment) {
                if (in_array($enrollment->user_id, $excludedUserIds, true)
                    || in_array($enrollment->student?->user_id, $excludedUserIds, true)) {
                    continue;
                }

                $this->addUser($emails, $enrollment->user);

                if ($enrollment->student instanceof Student) {
                    $this->addStudent($emails, $enrollment->student);
                }
            }
        }

        $attendees = EventAttendee::query()
            ->with('attendee')
            ->where('event_id', $event->id)
            ->get();

        foreach ($attendees as $eventAttendee) {
            $attendee = $eventAttendee->attendee;

            if ($attendee instanceof User) {
                if (! in_array($attendee->id, $excludedUserIds, true)) {
                    $this->addUser($emails, $attendee);
                }

                continue;
            }

            if (! $attendee instanceof Student) {
                continue;
            }

            $attendee->loadMissing(['user', 'additionalEmails']);

            if (in_array($attendee->user_id, $excludedUserIds, true)) {
                continue;
            }

            $this->addStudent($emails, $attendee);
        }

        return array_values($emails);
    }

    /**
     * @param  array<string, string>  $emails
     */
    private function addStudent(array &$emails, Student $student): void
    {
        $user = $student->user;

        if ($user instanceof User) {
            $this->addUser($emails, $user);
        }

        foreach ($student->additionalEmails as $studentEmail) {
            $this->addEmail($emails, $studentEmail->email);
        }
    }

    /**
     * @param  array<string, string>  $emails
     */
    private function addUser(array &$emails, ?User $user): void
    {
        if ($user instanceof User) {
            $this->addEmail($emails, $user->email);
        }
    }

    /**
     * @param  array<string, string>  $emails
     */
    private function addEmail(array &$emails, mixed $email): void
    {
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $emails[mb_strtolower($email)] ??= $email;
    }
}
