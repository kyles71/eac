<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

abstract class InstallmentPaymentEmailType implements EmailTypeContract
{
    abstract protected function key(): string;

    abstract protected function name(): string;

    abstract protected function description(): string;

    abstract protected function subject(): string;

    abstract protected function body(): string;

    final public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: $this->key(),
            names: ['en' => $this->name()],
            description: $this->description(),
            category: 'transactional',
            subjects: ['en' => $this->subject()],
            bodies: ['en' => $this->body()],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'User first name', example: 'Kyle'),
                new Token('user.full_name', 'User full name', example: 'Kyle Example'),
                new Token('user.email', 'User email address', example: 'kyle@example.com'),
                new Token('payment.outcome', 'Payment outcome', example: 'Successful'),
                new Token('payment.amount', 'Processed payment amount', example: '$50.00'),
                new Token('payment.processed_at', 'Payment processing date and time', example: 'June 19, 2026 8:00 AM EDT'),
                new Token('stripe.status', 'Stripe payment status', example: 'succeeded'),
                new Token('stripe.payment_intent_id', 'Stripe PaymentIntent ID', example: 'pi_123'),
                new Token('stripe.customer_id', 'Stripe customer ID', example: 'cus_123'),
                new Token('stripe.payment_method_id', 'Stripe payment method ID', example: 'pm_123'),
                new Token('stripe.failure_reason', 'Customer-safe failure reason', example: 'Your card was declined.'),
                new Token('stripe.failure_code', 'Stripe failure or decline code', example: 'card_declined'),
                new Token('installment.number', 'Installment number', example: '2'),
                new Token('installment.amount', 'Installment amount', example: '$50.00'),
                new Token('installment.due_date', 'Installment due date', example: 'June 19, 2026'),
                new Token('installment.status', 'Current installment status', example: 'Paid'),
                new Token('installment.retry_count', 'Failed attempt count', example: '1'),
                new Token('payment_plan.number', 'Payment plan number', example: '42'),
                new Token('payment_plan.total', 'Payment plan total', example: '$150.00'),
                new Token('payment_plan.paid', 'Amount paid on the plan', example: '$100.00'),
                new Token('payment_plan.remaining', 'Remaining payment plan balance', example: '$50.00'),
                new Token('payment_plan.installment_count', 'Total number of installments', example: '3'),
                new Token('order.number', 'Order number', example: '1234'),
                new Token('order.date', 'Order date', example: 'May 19, 2026'),
                new Token('order.total', 'Order total', example: '$150.00'),
            ],
            slots: [
                new SystemSlot(
                    key: 'payment-details',
                    label: 'Stripe and payment details',
                    previewHtml: '<p>Payment outcome, amount, processing time, Stripe status, reference, and failure details appear here.</p>',
                ),
                new SystemSlot(
                    key: 'payment-plan-details',
                    label: 'Payment plan details',
                    previewHtml: '<p>Installment progress, plan totals, paid amount, and remaining balance appear here.</p>',
                ),
                new SystemSlot(
                    key: 'order-details',
                    label: 'Order details',
                    previewHtml: '<p>Order number, date, purchased items, and total appear here.</p>',
                ),
            ],
        );
    }
}
