<?php

declare(strict_types=1);

use App\Actions\Store\SwitchPaymentPlanMethod;
use App\Enums\PaymentPlanMethod;
use App\Models\PaymentPlan;

it('switches from auto-charge to manual invoice', function () {
    $plan = PaymentPlan::factory()->create([
        'method' => PaymentPlanMethod::AutoCharge,
    ]);

    $action = new SwitchPaymentPlanMethod;
    $action->handle($plan, PaymentPlanMethod::ManualInvoice);

    expect($plan->refresh()->method)->toBe(PaymentPlanMethod::ManualInvoice);
});

it('switches from manual invoice to auto-charge when payment method exists', function () {
    $plan = PaymentPlan::factory()->create([
        'method' => PaymentPlanMethod::ManualInvoice,
        'stripe_payment_method_id' => 'pm_test_123',
    ]);

    $action = new SwitchPaymentPlanMethod;
    $action->handle($plan, PaymentPlanMethod::AutoCharge);

    expect($plan->refresh()->method)->toBe(PaymentPlanMethod::AutoCharge);
});

it('throws when switching to same method', function () {
    $plan = PaymentPlan::factory()->create([
        'method' => PaymentPlanMethod::AutoCharge,
    ]);

    $action = new SwitchPaymentPlanMethod;
    $action->handle($plan, PaymentPlanMethod::AutoCharge);
})->throws(InvalidArgumentException::class, 'already using this method');

it('throws when switching to auto-charge without payment method', function () {
    $plan = PaymentPlan::factory()->manualInvoice()->create();

    $action = new SwitchPaymentPlanMethod;
    $action->handle($plan, PaymentPlanMethod::AutoCharge);
})->throws(InvalidArgumentException::class, 'without a saved payment method');
