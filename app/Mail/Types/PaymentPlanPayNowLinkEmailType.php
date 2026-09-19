<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class PaymentPlanPayNowLinkEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'payment-plan-pay-now-link',
            names: ['en' => 'Payment Plan Pay Now Link'],
            description: 'Sent by an administrator so a customer can securely pay missed installments.',
            category: 'transactional',
            subjects: ['en' => 'Payment needed for order #{{ order.number }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Your payment plan for order #{{ order.number }} has one or more missed installments.</p>
                <p>Use the secure link below to choose the installments and payment method.</p>
                {{ slot.action }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'Customer first name', example: 'Jamie'),
                new Token('payment_plan.number', 'Payment plan number', example: '42'),
                new Token('order.number', 'Order number', example: '1234'),
            ],
            slots: [
                new SystemSlot(
                    key: 'action',
                    label: 'Secure Pay Now link',
                    previewHtml: '<p><a href="#">Pay missed installments</a></p>',
                ),
            ],
        );
    }
}
