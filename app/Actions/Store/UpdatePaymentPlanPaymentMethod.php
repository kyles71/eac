<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\PaymentPlan;
use InvalidArgumentException;

final readonly class UpdatePaymentPlanPaymentMethod
{
    public function handle(PaymentPlan $paymentPlan, string $stripePaymentMethodId): void
    {
        if ($stripePaymentMethodId === '') {
            throw new InvalidArgumentException('Choose a saved payment method.');
        }

        if ($paymentPlan->stripe_payment_method_id === $stripePaymentMethodId) {
            throw new InvalidArgumentException('Payment plan is already using this payment method.');
        }

        $paymentPlan->update([
            'stripe_payment_method_id' => $stripePaymentMethodId,
        ]);
    }
}
