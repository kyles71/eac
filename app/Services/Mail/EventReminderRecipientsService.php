<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Enrollment;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;

final readonly class EventReminderRecipientsService
{
    /**
     * @return list<array{emails: list<string>, user: ?User, student: ?Student}>
     */
    public function for(Event $event): array
    {
        if (! $event->start_time instanceof CarbonInterface) {
            return [];
        }

        $cutoff = $event->start_time->copy()->subWeeks(2);
        $excludedUserIds = $event->excludedUsers()->pluck('users.id')->all();
        $recipients = [];

        if ($event->course_id !== null) {
            $enrollments = Enrollment::query()
                ->with(['user', 'student.user', 'student.additionalEmails'])
                ->where('course_id', $event->course_id)
                ->whereNotNull('student_id')
                ->where('updated_at', '<=', $cutoff)
                ->get();

            foreach ($enrollments as $enrollment) {
                if (! $enrollment->student instanceof Student
                    || in_array($enrollment->user_id, $excludedUserIds, true)
                    || in_array($enrollment->student->user_id, $excludedUserIds, true)) {
                    continue;
                }

                $this->addStudent($recipients, $enrollment->student, $enrollment->user);
            }
        }

        $attendees = EventAttendee::query()
            ->with('attendee')
            ->where('event_id', $event->id)
            ->where('created_at', '<=', $cutoff)
            ->get();

        foreach ($attendees as $eventAttendee) {
            $attendee = $eventAttendee->attendee;

            if ($attendee instanceof Student) {
                $attendee->loadMissing(['user', 'additionalEmails']);

                if (! in_array($attendee->user_id, $excludedUserIds, true)) {
                    $this->addStudent($recipients, $attendee, $attendee->user);
                }
            } elseif ($attendee instanceof User && ! in_array($attendee->id, $excludedUserIds, true)) {
                $this->addUser($recipients, $attendee);
            }
        }

        return $this->deduplicateEmails(array_values($recipients));
    }

    /**
     * @param  array<string, array{emails: array<string, string>, user: ?User, student: ?Student}>  $recipients
     */
    private function addStudent(array &$recipients, Student $student, ?User $user): void
    {
        $key = "student:{$student->id}";
        $recipients[$key] ??= [
            'emails' => [],
            'user' => $user ?? $student->user,
            'student' => $student,
        ];

        $this->addEmail($recipients[$key]['emails'], $user?->email);
        $this->addEmail($recipients[$key]['emails'], $student->user?->email);

        foreach ($student->additionalEmails as $studentEmail) {
            $this->addEmail($recipients[$key]['emails'], $studentEmail->email);
        }
    }

    /**
     * @param  array<string, array{emails: array<string, string>, user: ?User, student: ?Student}>  $recipients
     */
    private function addUser(array &$recipients, User $user): void
    {
        $key = "user:{$user->id}";
        $recipients[$key] ??= [
            'emails' => [],
            'user' => $user,
            'student' => null,
        ];

        $this->addEmail($recipients[$key]['emails'], $user->email);
    }

    /** @param array<string, string> $emails */
    private function addEmail(array &$emails, mixed $email): void
    {
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $emails[mb_strtolower($email)] ??= $email;
    }

    /**
     * @param  list<array{emails: array<string, string>, user: ?User, student: ?Student}>  $recipients
     * @return list<array{emails: list<string>, user: ?User, student: ?Student}>
     */
    private function deduplicateEmails(array $recipients): array
    {
        $claimedEmails = [];
        $deduplicated = [];

        foreach ($recipients as $recipient) {
            $emails = [];

            foreach ($recipient['emails'] as $normalized => $email) {
                if (isset($claimedEmails[$normalized])) {
                    continue;
                }

                $claimedEmails[$normalized] = true;
                $emails[] = $email;
            }

            if ($emails !== []) {
                $deduplicated[] = [
                    'emails' => $emails,
                    'user' => $recipient['user'],
                    'student' => $recipient['student'],
                ];
            }
        }

        return $deduplicated;
    }
}
