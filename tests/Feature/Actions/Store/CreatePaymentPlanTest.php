<?php

declare(strict_types=1);

use App\Actions\Store\CreatePaymentPlan;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanFrequency;
use App\Models\Order;
use App\Models\PaymentPlanTemplate;

it('creates a payment plan with correct installments', function () {
    $order = Order::factory()->create(['total' => 10000]);
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
        'frequency' => PaymentPlanFrequency::Monthly,
    ]);

    $action = new CreatePaymentPlan;
    $plan = $action->handle(
        order: $order,
        template: $template,
        stripeCustomerId: 'cus_test_123',
        stripePaymentMethodId: 'pm_test_123',
    );

    expect($plan->total_amount)->toBe(10000)
        ->and($plan->number_of_installments)->toBe(3)
        ->and($plan->frequency)->toBe(PaymentPlanFrequency::Monthly)
        ->and($plan->stripe_customer_id)->toBe('cus_test_123')
        ->and($plan->stripe_payment_method_id)->toBe('pm_test_123')
        ->and($plan->installments)->toHaveCount(3);
});

it('creates installments from the stored payment plan principal and fee', function () {
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
        'frequency' => PaymentPlanFrequency::Monthly,
    ]);
    $order = Order::factory()->create([
        'total' => 8150,
        'payment_plan_principal' => 5000,
        'payment_plan_fee' => 150,
        'payment_plan_template_id' => $template->id,
    ]);

    $plan = (new CreatePaymentPlan)->handle($order, $template);

    expect($plan->total_amount)->toBe(5150)
        ->and($plan->installments)->toHaveCount(4)
        ->and($plan->installments->sum('amount'))->toBe(5150)
        ->and($plan->installments->sortBy('installment_number')->first()->amount)->toBe(1289);
});

it('marks first installment as paid', function () {
    $order = Order::factory()->create(['total' => 10000]);
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
    ]);

    $action = new CreatePaymentPlan;
    $plan = $action->handle($order, $template);

    $first = $plan->installments->where('installment_number', 1)->first();
    $second = $plan->installments->where('installment_number', 2)->first();

    expect($first->status)->toBe(InstallmentStatus::Paid)
        ->and($first->paid_at)->not->toBeNull()
        ->and($second->status)->toBe(InstallmentStatus::Pending);
});

it('first installment absorbs remainder', function () {
    $order = Order::factory()->create(['total' => 10000]);
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
    ]);

    $action = new CreatePaymentPlan;
    $plan = $action->handle($order, $template);

    $installments = $plan->installments->sortBy('installment_number');

    expect($installments->first()->amount)->toBe(3334)
        ->and($installments->skip(1)->first()->amount)->toBe(3333)
        ->and($installments->sum('amount'))->toBe(10000);
});

it('sets correct due dates for weekly frequency', function () {
    $order = Order::factory()->create(['total' => 9000]);
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
        'frequency' => PaymentPlanFrequency::Weekly,
    ]);

    $action = new CreatePaymentPlan;
    $plan = $action->handle($order, $template);

    $installments = $plan->installments->sortBy('installment_number');
    $today = now()->startOfDay();

    expect($installments->get(0)->due_date->startOfDay()->equalTo($today))->toBeTrue()
        ->and($installments->get(1)->due_date->startOfDay()->equalTo($today->copy()->addDays(7)))->toBeTrue()
        ->and($installments->get(2)->due_date->startOfDay()->equalTo($today->copy()->addDays(14)))->toBeTrue();
});

it('sets correct due dates for monthly frequency', function () {
    $order = Order::factory()->create(['total' => 9000]);
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
        'frequency' => PaymentPlanFrequency::Monthly,
    ]);

    $action = new CreatePaymentPlan;
    $plan = $action->handle($order, $template);

    $installments = $plan->installments->sortBy('installment_number');
    $today = now()->startOfDay();

    expect($installments->get(0)->due_date->startOfDay()->equalTo($today))->toBeTrue()
        ->and($installments->get(1)->due_date->startOfDay()->equalTo($today->copy()->addDays(30)))->toBeTrue()
        ->and($installments->get(2)->due_date->startOfDay()->equalTo($today->copy()->addDays(60)))->toBeTrue();
});

it('sets correct due dates for daily frequency', function () {
    $order = Order::factory()->create(['total' => 9000]);
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
        'frequency' => PaymentPlanFrequency::Daily,
    ]);

    $action = new CreatePaymentPlan;
    $plan = $action->handle($order, $template);

    $installments = $plan->installments->sortBy('installment_number');
    $today = now()->startOfDay();

    expect($installments->get(0)->due_date->startOfDay()->equalTo($today))->toBeTrue()
        ->and($installments->get(1)->due_date->startOfDay()->equalTo($today->copy()->addDay()))->toBeTrue()
        ->and($installments->get(2)->due_date->startOfDay()->equalTo($today->copy()->addDays(2)))->toBeTrue();
});
