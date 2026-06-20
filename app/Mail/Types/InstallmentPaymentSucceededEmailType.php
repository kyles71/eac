<?php

declare(strict_types=1);

namespace App\Mail\Types;

final class InstallmentPaymentSucceededEmailType extends InstallmentPaymentEmailType
{
    protected function key(): string
    {
        return 'payment-plan-installment-succeeded';
    }

    protected function name(): string
    {
        return 'Payment Plan Installment Succeeded';
    }

    protected function description(): string
    {
        return 'Sent after a scheduled payment plan installment is processed successfully.';
    }

    protected function subject(): string
    {
        return 'Payment received for order #{{ order.number }}';
    }

    protected function body(): string
    {
        return <<<'HTML'
            <p>Hello {{ user.first_name }},</p>
            <p>We successfully processed your payment of {{ payment.amount }} for installment #{{ installment.number }}.</p>
            {{ slot.payment-details }}
            {{ slot.payment-plan-details }}
            {{ slot.order-details }}
            HTML;
    }
}
