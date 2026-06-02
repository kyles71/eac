<?php

declare(strict_types=1);

use App\Actions\Store\UpdatePaymentPlanPaymentMethod;
use App\Models\PaymentPlan;

it('updates the saved payment method', function () {
    $plan = PaymentPlan::factory()->create([
        'stripe_payment_method_id' => 'pm_old',
    ]);

    $action = new UpdatePaymentPlanPaymentMethod;
    $action->handle($plan, 'pm_new');

    expect($plan->refresh()->stripe_payment_method_id)->toBe('pm_new');
});

it('throws when updating to the same payment method', function () {
    $plan = PaymentPlan::factory()->create([
        'stripe_payment_method_id' => 'pm_test_123',
    ]);

    $action = new UpdatePaymentPlanPaymentMethod;
    $action->handle($plan, 'pm_test_123');
})->throws(InvalidArgumentException::class, 'already using this payment method');

it('throws when no saved payment method is provided', function () {
    $plan = PaymentPlan::factory()->create();

    $action = new UpdatePaymentPlanPaymentMethod;
    $action->handle($plan, '');
})->throws(InvalidArgumentException::class, 'Choose a saved payment method');
