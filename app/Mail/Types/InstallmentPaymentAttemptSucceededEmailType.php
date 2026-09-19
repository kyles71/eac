<?php

declare(strict_types=1);

namespace App\Mail\Types;

final class InstallmentPaymentAttemptSucceededEmailType extends InstallmentPaymentAttemptEmailType
{
    protected function key(): string
    {
        return 'payment-plan-catch-up-succeeded';
    }

    protected function name(): string
    {
        return 'Payment Plan Catch-up Payment Succeeded';
    }

    protected function description(): string
    {
        return 'Sent after one combined payment successfully collects missed installments.';
    }

    protected function body(): string
    {
        return <<<'HTML'
            <p>Hello {{ user.first_name }},</p>
            <p>Your payment of {{ payment.amount }} for order #{{ order.number }} was successful.</p>
            {{ slot.installments }}
            <p>Remaining payment plan balance: {{ payment_plan.remaining }}</p>
            <p>Payment reference: {{ payment.reference }}</p>
            HTML;
    }
}
