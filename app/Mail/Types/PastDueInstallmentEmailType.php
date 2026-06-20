<?php

declare(strict_types=1);

namespace App\Mail\Types;

final class PastDueInstallmentEmailType extends InstallmentPaymentEmailType
{
    protected function key(): string
    {
        return 'payment-plan-past-due';
    }

    protected function name(): string
    {
        return 'Payment Plan Past Due';
    }

    protected function description(): string
    {
        return 'Sent to the configured administrator after an installment reaches three failed attempts.';
    }

    protected function subject(): string
    {
        return 'Payment plan past due for {{ user.full_name }} (order #{{ order.number }})';
    }

    protected function body(): string
    {
        return <<<'HTML'
            <p>The payment plan for {{ user.full_name }} ({{ user.email }}) is now past due after {{ installment.retry_count }} failed attempts.</p>
            {{ slot.payment-details }}
            {{ slot.payment-plan-details }}
            {{ slot.order-details }}
            HTML;
    }
}
