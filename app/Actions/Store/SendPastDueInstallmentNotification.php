<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\Mail\QueueManagedEmail;
use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Services\Mail\InstallmentPaymentContentService;

final readonly class SendPastDueInstallmentNotification
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private InstallmentPaymentContentService $content,
    ) {}

    public function handle(Installment $installment): bool
    {
        $installment->refresh();

        if ($installment->status !== InstallmentStatus::Overdue
            || $installment->retry_count < 3
            || $installment->past_due_notification_sent_at !== null) {
            return false;
        }

        $recipient = (string) config('mail.payment_plan_past_due_recipient');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $payload = $this->content->for(
            installment: $installment,
            successful: false,
            stripeStatus: $installment->last_payment_status,
            stripePaymentIntentId: $installment->stripe_payment_intent_id,
            stripeCustomerId: $installment->paymentPlan?->order?->user->stripe_id
                ?? $installment->paymentPlan?->stripe_customer_id,
            stripePaymentMethodId: $installment->paymentPlan?->stripe_payment_method_id,
            failureReason: $installment->last_failure_reason,
            failureCode: $installment->last_failure_code,
        );

        $queued = $this->managedEmail->handle(
            recipients: $recipient,
            emailTypeKey: 'payment-plan-past-due',
            tokens: $payload['tokens'],
            slots: $payload['slots'],
        );

        if ($queued) {
            $installment->update(['past_due_notification_sent_at' => now()]);
        }

        return $queued;
    }
}
