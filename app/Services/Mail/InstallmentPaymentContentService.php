<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Installment;
use App\Models\Order;
use App\Models\PaymentPlan;
use LogicException;

final readonly class InstallmentPaymentContentService
{
    /**
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(
        Installment $installment,
        bool $successful,
        ?string $stripeStatus = null,
        ?string $stripePaymentIntentId = null,
        ?string $stripeCustomerId = null,
        ?string $stripePaymentMethodId = null,
        ?string $failureReason = null,
        ?string $failureCode = null,
    ): array {
        $installment->loadMissing([
            'paymentPlan.template',
            'paymentPlan.installments',
            'paymentPlan.order.user',
            'paymentPlan.order.orderItems.product',
        ]);

        $paymentPlan = $installment->paymentPlan;

        if (! $paymentPlan instanceof PaymentPlan || ! $paymentPlan->order instanceof Order) {
            throw new LogicException('The installment payment plan and order are required.');
        }

        $order = $paymentPlan->order;
        $processedAt = $installment->updated_at ?? now();

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $order->user->first_name,
                'user.full_name' => $order->user->full_name,
                'user.email' => $order->user->email,
                'payment.outcome' => $successful ? 'Successful' : 'Failed',
                'payment.amount' => format_money($installment->amount),
                'payment.processed_at' => $processedAt->format('F j, Y g:i A T'),
                'stripe.status' => $stripeStatus ?? ($successful ? 'succeeded' : 'failed'),
                'stripe.payment_intent_id' => $stripePaymentIntentId ?? '',
                'stripe.customer_id' => $stripeCustomerId ?? '',
                'stripe.payment_method_id' => $stripePaymentMethodId ?? '',
                'stripe.failure_reason' => $failureReason ?? '',
                'stripe.failure_code' => $failureCode ?? '',
                'installment.number' => (string) $installment->installment_number,
                'installment.amount' => format_money($installment->amount),
                'installment.due_date' => $installment->due_date->format('F j, Y'),
                'installment.status' => $installment->status->value,
                'installment.retry_count' => (string) $installment->retry_count,
                'payment_plan.number' => (string) $paymentPlan->id,
                'payment_plan.total' => format_money($paymentPlan->total_amount),
                'payment_plan.paid' => format_money($paymentPlan->amountPaid()),
                'payment_plan.remaining' => format_money($paymentPlan->remainingBalance()),
                'payment_plan.installment_count' => (string) $paymentPlan->number_of_installments,
                'order.number' => (string) $order->id,
                'order.date' => $order->created_at->format('F j, Y'),
                'order.total' => $order->formattedTotal(),
            ],
            'slots' => [
                'payment-details' => view('mail.installment-payment-details', [
                    'installment' => $installment,
                    'successful' => $successful,
                    'stripeStatus' => $stripeStatus,
                    'stripePaymentIntentId' => $stripePaymentIntentId,
                    'stripeCustomerId' => $stripeCustomerId,
                    'stripePaymentMethodId' => $stripePaymentMethodId,
                    'failureReason' => $failureReason,
                    'failureCode' => $failureCode,
                    'processedAt' => $processedAt,
                ])->render(),
                'payment-plan-details' => view('mail.installment-payment-plan-details', [
                    'installment' => $installment,
                    'paymentPlan' => $paymentPlan,
                ])->render(),
                'order-details' => view('mail.installment-order-details', [
                    'order' => $order,
                ])->render(),
            ],
        ];
    }
}
