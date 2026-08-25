<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Models\RecurringPrivateLessonBillingPeriod;
use App\Models\RecurringPrivateLessonCharge;
use App\Services\Mail\RecurringPrivateLessonContentService;
use App\Services\Mail\RecurringPrivateLessonRecipientsService;
use Carbon\CarbonInterface;

final readonly class SendRecurringPrivateLessonEmail
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private RecurringPrivateLessonRecipientsService $recipients,
        private RecurringPrivateLessonContentService $content,
    ) {}

    public function billingPeriod(RecurringPrivateLessonBillingPeriod $billingPeriod): int
    {
        $billingPeriod->loadMissing('recurringPrivateLesson');

        return $this->queueForRecipients(
            $this->recipients->all($billingPeriod->recurringPrivateLesson),
            'recurring-private-lesson-billing',
            $this->content->forBillingPeriod($billingPeriod),
        );
    }

    public function paymentReminder(RecurringPrivateLessonCharge $charge, int $days): int
    {
        $charge->loadMissing('recurringPrivateLesson');
        $recipients = $charge->billed_at === null
            ? $this->recipients->staff($charge->recurringPrivateLesson)
            : $this->recipients->all($charge->recurringPrivateLesson);

        return $this->queueForRecipients(
            $recipients,
            'recurring-private-lesson-payment-reminder',
            $this->content->forCharge($charge, $days),
        );
    }

    public function automaticCancellation(RecurringPrivateLessonCharge $charge): int
    {
        $charge->loadMissing('recurringPrivateLesson');

        return $this->queueForRecipients(
            $this->recipients->all($charge->recurringPrivateLesson),
            'recurring-private-lesson-automatic-cancellation',
            $this->content->forCharge($charge),
        );
    }

    public function rescheduled(
        RecurringPrivateLessonCharge $charge,
        CarbonInterface $previousStartsAt,
        string $reason,
    ): int {
        $charge->loadMissing('recurringPrivateLesson');

        return $this->queueForRecipients(
            $this->recipients->householdAndTeachers($charge->recurringPrivateLesson),
            'recurring-private-lesson-rescheduled',
            $this->content->forManagedChange($charge, $previousStartsAt, $reason),
        );
    }

    public function removed(
        RecurringPrivateLessonCharge $charge,
        CarbonInterface $previousStartsAt,
        string $reason,
    ): int {
        $charge->loadMissing('recurringPrivateLesson');

        return $this->queueForRecipients(
            $this->recipients->householdAndTeachers($charge->recurringPrivateLesson),
            'recurring-private-lesson-removed',
            $this->content->forManagedChange($charge, $previousStartsAt, $reason),
        );
    }

    /**
     * @param  list<string>  $recipients
     * @param  array{tokens: array<string, string>, slots: array<string, string>}  $payload
     */
    private function queueForRecipients(array $recipients, string $emailTypeKey, array $payload): int
    {
        $queued = 0;

        foreach ($recipients as $recipient) {
            if ($this->managedEmail->handle(
                recipients: $recipient,
                emailTypeKey: $emailTypeKey,
                tokens: $payload['tokens'],
                slots: $payload['slots'],
            )) {
                $queued++;
            }
        }

        return $queued;
    }
}
