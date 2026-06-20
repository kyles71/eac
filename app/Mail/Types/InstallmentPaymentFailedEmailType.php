<?php

declare(strict_types=1);

namespace App\Mail\Types;

final class InstallmentPaymentFailedEmailType extends InstallmentPaymentEmailType
{
    protected function key(): string
    {
        return 'payment-plan-installment-failed';
    }

    protected function name(): string
    {
        return 'Payment Plan Installment Failed';
    }

    protected function description(): string
    {
        return 'Sent after a scheduled payment plan installment attempt fails.';
    }

    protected function subject(): string
    {
        return 'Payment failed for order #{{ order.number }}';
    }

    protected function body(): string
    {
        return <<<'HTML'
            <p>Hello {{ user.first_name }},</p>
            <p>We could not process your payment of {{ payment.amount }} for installment #{{ installment.number }}.</p>
            {{ slot.payment-details }}
            {{ slot.payment-plan-details }}
            {{ slot.order-details }}
            HTML;
    }
}
