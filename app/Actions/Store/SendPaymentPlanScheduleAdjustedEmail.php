<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\Mail\QueueManagedEmail;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Services\Mail\PaymentPlanScheduleAdjustedContentService;
use App\Services\PaymentPlanScheduleEmailAvailabilityService;

final readonly class SendPaymentPlanScheduleAdjustedEmail
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private PaymentPlanScheduleAdjustedContentService $content,
    ) {}

    public function handle(PaymentPlan $paymentPlan, string $reason): bool
    {
        $paymentPlan->loadMissing('order.user');
        $user = $paymentPlan->order?->user;

        if (! $user instanceof User || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $payload = $this->content->for($paymentPlan, $reason);

        return $this->managedEmail->handle(
            recipients: $user->email,
            emailTypeKey: PaymentPlanScheduleEmailAvailabilityService::EMAIL_TYPE_KEY,
            tokens: $payload['tokens'],
            slots: $payload['slots'],
        );
    }
}
