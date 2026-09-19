<?php

declare(strict_types=1);

namespace App\Mail\Types;

final class InstallmentPaymentAttemptFailedEmailType extends InstallmentPaymentAttemptEmailType
{
    protected function key(): string
    {
        return 'payment-plan-catch-up-failed';
    }

    protected function name(): string
    {
        return 'Payment Plan Catch-up Payment Failed';
    }

    protected function description(): string
    {
        return 'Sent after one combined payment for missed installments fails.';
    }

    protected function body(): string
    {
        return <<<'HTML'
            <p>Hello {{ user.first_name }},</p>
            <p>We could not process your payment of {{ payment.amount }} for order #{{ order.number }}.</p>
            <p>{{ payment.failure_reason }}</p>
            {{ slot.installments }}
            HTML;
    }
}
