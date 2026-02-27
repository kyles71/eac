<?php

declare(strict_types=1);

use App\Actions\Store\ProcessInstallments;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\Installment;
use App\Models\PaymentPlan;
use Stripe\Invoice;
use Stripe\PaymentIntent;

beforeEach(function () {
    $this->mockStripe = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $this->mockStripe);
});

it('processes due auto-charge installments successfully', function () {
    $plan = PaymentPlan::factory()->create([
        'method' => PaymentPlanMethod::AutoCharge,
        'stripe_customer_id' => 'cus_test_123',
        'stripe_payment_method_id' => 'pm_test_123',
    ]);

    $installment = Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3333,
    ]);

    $paymentIntent = PaymentIntent::constructFrom(['id' => 'pi_result_123', 'status' => 'succeeded']);

    $this->mockStripe
        ->shouldReceive('chargePaymentMethod')
        ->once()
        ->andReturn($paymentIntent);

    $action = app(ProcessInstallments::class);
    $result = $action->handle();

    expect($result['processed'])->toBe(1)
        ->and($result['succeeded'])->toBe(1)
        ->and($result['failed'])->toBe(0);

    $installment->refresh();
    expect($installment->status)->toBe(InstallmentStatus::Paid)
        ->and($installment->stripe_payment_intent_id)->toBe('pi_result_123');
});

it('marks installment as failed when auto-charge fails', function () {
    $plan = PaymentPlan::factory()->create([
        'method' => PaymentPlanMethod::AutoCharge,
        'stripe_customer_id' => 'cus_test_123',
        'stripe_payment_method_id' => 'pm_test_123',
    ]);

    $installment = Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3333,
    ]);

    $this->mockStripe
        ->shouldReceive('chargePaymentMethod')
        ->once()
        ->andThrow(new Exception('Card declined'));

    $action = app(ProcessInstallments::class);
    $result = $action->handle();

    expect($result['processed'])->toBe(1)
        ->and($result['succeeded'])->toBe(0)
        ->and($result['failed'])->toBe(1);

    $installment->refresh();
    expect($installment->status)->toBe(InstallmentStatus::Failed)
        ->and($installment->retry_count)->toBe(1);
});

it('processes manual invoice installments', function () {
    $plan = PaymentPlan::factory()->manualInvoice()->create([
        'stripe_customer_id' => 'cus_test_123',
    ]);

    $installment = Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3333,
    ]);

    $invoice = Invoice::constructFrom(['id' => 'inv_test_123']);

    $this->mockStripe
        ->shouldReceive('createAndSendInvoice')
        ->once()
        ->andReturn($invoice);

    $action = app(ProcessInstallments::class);
    $result = $action->handle();

    expect($result['processed'])->toBe(1)
        ->and($result['succeeded'])->toBe(1);

    $installment->refresh();
    expect($installment->stripe_invoice_id)->toBe('inv_test_123');
});

it('retries failed installments', function () {
    $plan = PaymentPlan::factory()->create([
        'method' => PaymentPlanMethod::AutoCharge,
        'stripe_customer_id' => 'cus_test_123',
        'stripe_payment_method_id' => 'pm_test_123',
    ]);

    $installment = Installment::factory()->failed(1)->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3333,
    ]);

    $paymentIntent = PaymentIntent::constructFrom(['id' => 'pi_retry_123', 'status' => 'succeeded']);

    $this->mockStripe
        ->shouldReceive('chargePaymentMethod')
        ->once()
        ->andReturn($paymentIntent);

    $action = app(ProcessInstallments::class);
    $result = $action->handle();

    expect($result['succeeded'])->toBe(1);

    $installment->refresh();
    expect($installment->status)->toBe(InstallmentStatus::Paid);
});

it('does not process overdue installments', function () {
    PaymentPlan::factory()->create();

    Installment::factory()->overdue()->create();

    $action = app(ProcessInstallments::class);
    $result = $action->handle();

    expect($result['processed'])->toBe(0);
});

it('does not process future installments', function () {
    Installment::factory()->create([
        'due_date' => now()->addMonth(),
        'status' => InstallmentStatus::Pending,
    ]);

    $action = app(ProcessInstallments::class);
    $result = $action->handle();

    expect($result['processed'])->toBe(0);
});

it('marks as failed when missing stripe credentials for auto-charge', function () {
    $plan = PaymentPlan::factory()->create([
        'method' => PaymentPlanMethod::AutoCharge,
        'stripe_customer_id' => null,
        'stripe_payment_method_id' => null,
    ]);

    $installment = Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3333,
    ]);

    $action = app(ProcessInstallments::class);
    $result = $action->handle();

    expect($result['failed'])->toBe(1);

    $installment->refresh();
    expect($installment->status)->toBe(InstallmentStatus::Failed);
});
