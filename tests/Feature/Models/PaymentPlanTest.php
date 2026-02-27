<?php

declare(strict_types=1);

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanFrequency;
use App\Enums\PaymentPlanMethod;
use App\Models\Installment;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;

it('can be created with factory', function () {
    $plan = PaymentPlan::factory()->create();

    expect($plan)->toBeInstanceOf(PaymentPlan::class)
        ->and($plan->method)->toBe(PaymentPlanMethod::AutoCharge)
        ->and($plan->frequency)->toBe(PaymentPlanFrequency::Monthly);
});

it('belongs to an order', function () {
    $order = Order::factory()->create();
    $plan = PaymentPlan::factory()->create(['order_id' => $order->id]);

    expect($plan->order->id)->toBe($order->id);
});

it('belongs to a template', function () {
    $template = PaymentPlanTemplate::factory()->create();
    $plan = PaymentPlan::factory()->create(['payment_plan_template_id' => $template->id]);

    expect($plan->template->id)->toBe($template->id);
});

it('has many installments', function () {
    $plan = PaymentPlan::factory()->create();
    Installment::factory()->count(3)->create(['payment_plan_id' => $plan->id]);

    expect($plan->installments)->toHaveCount(3);
});

it('calculates amount paid', function () {
    $plan = PaymentPlan::factory()->create(['total_amount' => 10000]);

    Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3334,
    ]);
    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3333,
        'status' => InstallmentStatus::Pending,
    ]);

    expect($plan->amountPaid())->toBe(3334);
});

it('calculates remaining balance', function () {
    $plan = PaymentPlan::factory()->create(['total_amount' => 10000]);

    Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3334,
    ]);

    expect($plan->remainingBalance())->toBe(6666);
});

it('detects fully paid status', function () {
    $plan = PaymentPlan::factory()->create(['total_amount' => 10000]);

    Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 5000,
    ]);
    Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 5000,
    ]);

    expect($plan->isFullyPaid())->toBeTrue();
});

it('is not fully paid when installments remain', function () {
    $plan = PaymentPlan::factory()->create(['total_amount' => 10000]);

    Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 5000,
    ]);
    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 5000,
        'status' => InstallmentStatus::Pending,
    ]);

    expect($plan->isFullyPaid())->toBeFalse();
});
