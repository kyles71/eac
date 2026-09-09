<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Event;
use App\Models\OrderItemFulfillment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class OrderFulfillmentRecipientsService
{
    /**
     * @param  Collection<int, OrderItemFulfillment>  $fulfillments
     * @return list<string>
     */
    public function for(Event $event, Collection $fulfillments): array
    {
        $event->loadMissing('teachers');
        $emails = [];

        foreach ($fulfillments as $fulfillment) {
            $fulfillment->loadMissing([
                'students.user',
                'students.additionalEmails',
                'orderItem.order.user.students.user',
                'orderItem.order.user.students.additionalEmails',
            ]);

            $students = $fulfillment->students->isNotEmpty()
                ? $fulfillment->students
                : $fulfillment->orderItem->order->user->students;

            foreach ($students as $student) {
                $this->addStudent($emails, $student);
            }
        }

        foreach ($event->teachers as $teacher) {
            $this->addUser($emails, $teacher);
        }

        return array_values($emails);
    }

    /** @param array<string, string> $emails */
    private function addStudent(array &$emails, Student $student): void
    {
        $this->addUser($emails, $student->user);

        foreach ($student->additionalEmails as $studentEmail) {
            $this->addEmail($emails, $studentEmail->email);
        }
    }

    /** @param array<string, string> $emails */
    private function addUser(array &$emails, ?User $user): void
    {
        if ($user instanceof User) {
            $this->addEmail($emails, $user->email);
        }
    }

    /** @param array<string, string> $emails */
    private function addEmail(array &$emails, mixed $email): void
    {
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[mb_strtolower($email)] ??= $email;
        }
    }
}
