<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\Order;
use App\Models\PaymentPlanTemplate;
use InvalidArgumentException;

final readonly class ConfirmCheckoutPayment
{
    public function __construct(
        private StripeServiceContract $stripeService,
        private CompleteOrder $completeOrder,
    ) {}

    /**
     * Verify payment and complete the order after successful Stripe Elements confirmation.
     */
    public function handle(
        Order $order,
        ?PaymentPlanTemplate $paymentPlanTemplate = null,
        ?PaymentPlanMethod $paymentPlanMethod = null,
    ): void {
        if ($order->status !== OrderStatus::Pending) {
            throw new InvalidArgumentException('Order is not in a pending state.');
        }

        if ($order->stripe_payment_intent_id === null) {
            throw new InvalidArgumentException('Order has no associated payment intent.');
        }

        // Retrieve and verify the PaymentIntent status
        $paymentIntent = $this->stripeService->retrievePaymentIntent($order->stripe_payment_intent_id);

        if ($paymentIntent->status !== 'succeeded') {
            throw new InvalidArgumentException('Payment has not been completed successfully.');
        }

        // Complete the order (enrollment, fulfillment, etc.)
        $this->completeOrder->handle($order);

        // Handle payment plan if applicable
        if ($paymentPlanTemplate !== null && $paymentPlanMethod !== null) {
            $stripeCustomerId = $paymentIntent->customer;
            $stripePaymentMethodId = $paymentIntent->payment_method;

            // Save payment method to user for future charges
            if ($stripePaymentMethodId !== null) {
                /** @var \App\Models\User $user */
                $user = $order->user;
                $user->update(['stripe_payment_method_id' => $stripePaymentMethodId]);
            }

            $createPaymentPlan = new CreatePaymentPlan;
            $createPaymentPlan->handle(
                order: $order,
                template: $paymentPlanTemplate,
                method: $paymentPlanMethod,
                stripeCustomerId: $stripeCustomerId,
                stripePaymentMethodId: $stripePaymentMethodId,
            );
        }
    }
}
