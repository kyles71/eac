<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\Mail\QueueManagedEmail;
use App\Models\Installment;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Services\Mail\InstallmentPaymentContent;

final readonly class SendInstallmentPaymentEmail
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private InstallmentPaymentContent $content,
    ) {}

    public function handle(
        Installment $installment,
        bool $successful,
        ?string $stripeStatus = null,
        ?string $stripePaymentIntentId = null,
        ?string $stripeCustomerId = null,
        ?string $stripePaymentMethodId = null,
        ?string $failureReason = null,
        ?string $failureCode = null,
    ): bool {
        $installment->loadMissing('paymentPlan.order.user');
        $paymentPlan = $installment->paymentPlan;
        $user = $paymentPlan instanceof PaymentPlan ? $paymentPlan->order?->user : null;

        if (! $user instanceof User || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $payload = $this->content->for(
            installment: $installment,
            successful: $successful,
            stripeStatus: $stripeStatus,
            stripePaymentIntentId: $stripePaymentIntentId,
            stripeCustomerId: $stripeCustomerId,
            stripePaymentMethodId: $stripePaymentMethodId,
            failureReason: $failureReason,
            failureCode: $failureCode,
        );

        return $this->managedEmail->handle(
            recipients: $user->email,
            emailTypeKey: $successful
                ? 'payment-plan-installment-succeeded'
                : 'payment-plan-installment-failed',
            tokens: $payload['tokens'],
            slots: $payload['slots'],
        );
    }
}
