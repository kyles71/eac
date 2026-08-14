<?php

declare(strict_types=1);

use App\Actions\Store\ProcessInstallments;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderRefundStatus;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PaymentPlan;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Stripe\PaymentIntent;

beforeEach(function () {
    Mail::fake();
    $this->mockStripe = Mockery::mock(StripeServiceContract::class);
    $this->mockStripe->shouldReceive('getDefaultPaymentMethodId')->byDefault()->andReturnNull();
    $this->app->instance(StripeServiceContract::class, $this->mockStripe);
});

it('processes due installments using the payment method assigned to the plan', function () {
    auth()->user()->update(['stripe_id' => 'cus_account_default']);

    $order = Order::factory()->create([
        'user_id' => auth()->id(),
    ]);

    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_legacy',
        'stripe_payment_method_id' => 'pm_plan',
    ]);

    $installment = Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3333,
    ]);

    $paymentIntent = PaymentIntent::constructFrom(['id' => 'pi_result_123', 'status' => 'succeeded']);

    $this->mockStripe
        ->shouldReceive('chargePaymentMethod')
        ->once()
        ->withArgs(fn (
            string $customerId,
            string $paymentMethodId,
            int $amount,
        ): bool => $customerId === 'cus_account_default'
            && $paymentMethodId === 'pm_plan'
            && $amount === 3333)
        ->andReturn($paymentIntent);

    $action = app(ProcessInstallments::class);
    $result = $action->handle();

    expect($result['processed'])->toBe(1)
        ->and($result['succeeded'])->toBe(1)
        ->and($result['failed'])->toBe(0);

    $installment->refresh();
    expect($installment->status)->toBe(InstallmentStatus::Paid)
        ->and($installment->stripe_payment_intent_id)->toBe('pi_result_123');

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail) use ($order): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'payment-plan-installment-succeeded'
            && $mail->hasTo($order->user->email)
            && $mail->usesMailer('transactional')
            && $rendered->subject === "Payment received for order #{$order->id}"
            && str_contains($rendered->html, 'pi_result_123')
            && str_contains($rendered->html, 'Installment')
            && str_contains($rendered->html, 'Order #');
    });
});

it('uses the account default when a legacy plan has no assigned payment method', function () {
    $plan = PaymentPlan::factory()->create([
        'stripe_customer_id' => 'cus_test_123',
        'stripe_payment_method_id' => null,
    ]);

    Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plan->id,
        'amount' => 3333,
    ]);

    $paymentIntent = PaymentIntent::constructFrom(['id' => 'pi_legacy_123', 'status' => 'succeeded']);

    $this->mockStripe
        ->shouldReceive('getDefaultPaymentMethodId')
        ->once()
        ->with('cus_test_123')
        ->andReturn('pm_default');

    $this->mockStripe
        ->shouldReceive('chargePaymentMethod')
        ->once()
        ->withArgs(fn (
            string $customerId,
            string $paymentMethodId,
        ): bool => $customerId === 'cus_test_123' && $paymentMethodId === 'pm_default')
        ->andReturn($paymentIntent);

    $result = app(ProcessInstallments::class)->handle();

    expect($result['succeeded'])->toBe(1);
});

it('retrieves each customer default only once for legacy plans without assignments', function () {
    $plans = PaymentPlan::factory()->count(2)->create([
        'stripe_customer_id' => 'cus_shared',
        'stripe_payment_method_id' => null,
    ]);

    $plans->each(fn (PaymentPlan $plan) => Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plan->id,
    ]));

    $this->mockStripe
        ->shouldReceive('getDefaultPaymentMethodId')
        ->once()
        ->with('cus_shared')
        ->andReturn('pm_default');
    $this->mockStripe
        ->shouldReceive('chargePaymentMethod')
        ->twice()
        ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_result', 'status' => 'succeeded']));

    $result = app(ProcessInstallments::class)->handle();

    expect($result['succeeded'])->toBe(2);
});

it('marks installment as failed when auto-charge fails', function () {
    $plan = PaymentPlan::factory()->create([
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

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'payment-plan-installment-failed'
            && $mail->usesMailer('transactional')
            && str_contains($rendered->html, 'We could not process this payment')
            && ! str_contains($rendered->html, 'Card declined');
    });
});

it('continues processing installments when a charge throws a runtime throwable', function () {
    $plans = PaymentPlan::factory()->count(2)->create([
        'stripe_customer_id' => 'cus_runtime_throwable',
        'stripe_payment_method_id' => 'pm_runtime_throwable',
    ]);

    $firstInstallment = Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plans[0]->id,
    ]);

    $secondInstallment = Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plans[1]->id,
    ]);

    $paymentIntent = PaymentIntent::constructFrom(['id' => 'pi_runtime_recovered', 'status' => 'succeeded']);
    $attempts = 0;

    $this->mockStripe
        ->shouldReceive('chargePaymentMethod')
        ->twice()
        ->andReturnUsing(function () use (&$attempts, $paymentIntent): PaymentIntent {
            $attempts++;

            if ($attempts === 1) {
                throw new TypeError('Stripe SDK returned an unexpected shape.');
            }

            return $paymentIntent;
        });

    $result = app(ProcessInstallments::class)->handle();

    expect($result['processed'])->toBe(2)
        ->and($result['succeeded'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and($firstInstallment->refresh()->status)->toBe(InstallmentStatus::Failed)
        ->and($firstInstallment->retry_count)->toBe(1)
        ->and($secondInstallment->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and($secondInstallment->stripe_payment_intent_id)->toBe('pi_runtime_recovered');
});

it('retries failed installments', function () {
    $plan = PaymentPlan::factory()->create([
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

it('does not consume more than one failed retry per eastern calendar day', function () {
    $this->travelTo('2026-06-19 12:00:00');
    $plan = PaymentPlan::factory()->create([
        'stripe_customer_id' => 'cus_daily_retry',
        'stripe_payment_method_id' => 'pm_daily_retry',
    ]);
    $installment = Installment::factory()->failed(1)->create([
        'payment_plan_id' => $plan->id,
        'last_attempted_at' => now()->subDay(),
    ]);

    $this->mockStripe
        ->shouldReceive('chargePaymentMethod')
        ->twice()
        ->andThrow(new Exception('Card declined'));

    expect(app(ProcessInstallments::class)->handle()['processed'])->toBe(1)
        ->and($installment->refresh()->retry_count)->toBe(2)
        ->and(app(ProcessInstallments::class)->handle()['processed'])->toBe(0)
        ->and($installment->refresh()->retry_count)->toBe(2);

    $this->travelTo('2026-06-20 12:00:00');

    expect(app(ProcessInstallments::class)->handle()['processed'])->toBe(1)
        ->and($installment->refresh()->status)->toBe(InstallmentStatus::Overdue)
        ->and($installment->retry_count)->toBe(3)
        ->and($installment->past_due_notification_sent_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'payment-plan-past-due'
        && $mail->hasTo('eacdance@outlook.com'));
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

it('does not charge installments while their cancellation refund is in flight', function (): void {
    $order = Order::factory()->completed()->create();
    $plan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    $installment = Installment::factory()->dueToday()->create([
        'payment_plan_id' => $plan->id,
    ]);
    OrderRefund::factory()->create([
        'order_id' => $order->id,
        'cancel_remaining_installments' => true,
        'status' => OrderRefundStatus::Pending,
    ]);

    $this->mockStripe->shouldNotReceive('chargePaymentMethod');

    expect(app(ProcessInstallments::class)->handle()['processed'])->toBe(0)
        ->and($installment->refresh()->status)->toBe(InstallmentStatus::Pending);
});
