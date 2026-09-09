<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\Mail\QueueManagedEmail;
use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Models\InstallmentPaymentAttempt;
use App\Models\User;

final readonly class SendInstallmentPaymentAttemptEmail
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private SendInstallmentPaymentEmail $installmentPaymentEmail,
    ) {}

    public function handle(InstallmentPaymentAttempt $paymentAttempt, bool $successful): bool
    {
        $paymentAttempt->loadMissing(['allocations.installment', 'paymentPlan.order.user']);
        $user = $paymentAttempt->paymentPlan->order?->user;

        if (! $user instanceof User || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($paymentAttempt->origin === InstallmentPaymentAttemptOrigin::Scheduled
            && $paymentAttempt->allocations->count() === 1) {
            return $this->installmentPaymentEmail->handle(
                installment: $paymentAttempt->allocations->firstOrFail()->installment,
                successful: $successful,
                stripeStatus: $paymentAttempt->stripe_status,
                stripePaymentIntentId: $paymentAttempt->stripe_payment_intent_id,
                stripeCustomerId: $paymentAttempt->stripe_customer_id,
                stripePaymentMethodId: $paymentAttempt->stripe_payment_method_id,
                failureReason: $paymentAttempt->failure_reason,
                failureCode: $paymentAttempt->failure_code,
            );
        }

        return $this->managedEmail->handle(
            recipients: $user->email,
            emailTypeKey: $successful
                ? 'payment-plan-catch-up-succeeded'
                : 'payment-plan-catch-up-failed',
            tokens: [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $user->first_name,
                'payment.amount' => format_money($paymentAttempt->total_amount),
                'payment.failure_reason' => $paymentAttempt->failure_reason ?? '',
                'payment.reference' => $paymentAttempt->stripe_payment_intent_id ?? '',
                'payment_plan.number' => (string) $paymentAttempt->payment_plan_id,
                'payment_plan.remaining' => format_money($paymentAttempt->paymentPlan->remainingBalance()),
                'order.number' => (string) $paymentAttempt->paymentPlan->order_id,
            ],
            slots: [
                'installments' => $this->installmentsHtml($paymentAttempt),
            ],
        );
    }

    private function installmentsHtml(InstallmentPaymentAttempt $paymentAttempt): string
    {
        $rows = $paymentAttempt->allocations
            ->sortBy(fn ($allocation): int => $allocation->installment->installment_number)
            ->map(fn ($allocation): string => '<tr>'
                .'<td style="padding:6px 8px">#'.e((string) $allocation->installment->installment_number).'</td>'
                .'<td style="padding:6px 8px">'.e($allocation->installment->due_date->format('F j, Y')).'</td>'
                .'<td style="padding:6px 8px">'.e(format_money($allocation->amount)).'</td>'
                .'</tr>')
            ->implode('');

        return '<table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:24px">'
            .'<tr><th align="left">Installment</th><th align="left">Due</th><th align="left">Amount</th></tr>'
            .$rows
            .'</table>';
    }
}
