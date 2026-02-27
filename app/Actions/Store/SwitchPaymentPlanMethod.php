<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\PaymentPlanMethod;
use App\Models\PaymentPlan;
use InvalidArgumentException;

final readonly class SwitchPaymentPlanMethod
{
    /**
     * Switch the payment method for a payment plan.
     */
    public function handle(PaymentPlan $paymentPlan, PaymentPlanMethod $newMethod): void
    {
        if ($paymentPlan->method === $newMethod) {
            throw new InvalidArgumentException('Payment plan is already using this method.');
        }

        if ($newMethod === PaymentPlanMethod::AutoCharge && $paymentPlan->stripe_payment_method_id === null) {
            throw new InvalidArgumentException('Cannot switch to auto-charge without a saved payment method.');
        }

        $paymentPlan->update(['method' => $newMethod]);
    }
}
