<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

abstract class InstallmentPaymentAttemptEmailType implements EmailTypeContract
{
    abstract protected function key(): string;

    abstract protected function name(): string;

    abstract protected function description(): string;

    abstract protected function body(): string;

    final public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: $this->key(),
            names: ['en' => $this->name()],
            description: $this->description(),
            category: 'transactional',
            subjects: ['en' => $this->name().' for order #{{ order.number }}'],
            bodies: ['en' => $this->body()],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'Customer first name', example: 'Jamie'),
                new Token('payment.amount', 'Combined payment amount', example: '$100.00'),
                new Token('payment.failure_reason', 'Customer-safe failure reason', example: 'Your card was declined.'),
                new Token('payment.reference', 'Stripe PaymentIntent reference', example: 'pi_123'),
                new Token('payment_plan.number', 'Payment plan number', example: '42'),
                new Token('payment_plan.remaining', 'Remaining payment plan balance', example: '$50.00'),
                new Token('order.number', 'Order number', example: '1234'),
            ],
            slots: [
                new SystemSlot(
                    key: 'installments',
                    label: 'Installments included in the payment',
                    previewHtml: '<p>Selected installment numbers, due dates, and amounts appear here.</p>',
                ),
            ],
        );
    }
}
