<?php

declare(strict_types=1);

use App\Actions\Store\CreateInstallmentPaymentAttempt;
use App\Actions\Store\FinalizeInstallmentPaymentAttempt;
use App\Actions\Store\ProcessInstallmentPaymentAttempt;
use App\Actions\Store\SendPaymentPlanPayNowLink;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentPaymentAttemptEmailStatus;
use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\InstallmentPaymentAttempt;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentIntent;

beforeEach(function (): void {
    Mail::fake();
});

it('collects selected missed installments in one payment and saves the method for future installments', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $customer->update(['stripe_id' => 'cus_customer']);
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_customer',
        'stripe_payment_method_id' => 'pm_old',
        'total_amount' => 9000,
    ]);
    $oldest = Installment::factory()->failed()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 3000,
        'due_date' => now()->subMonth(),
    ]);
    $overdue = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 3,
        'amount' => 3000,
        'due_date' => now()->subWeek(),
    ]);

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('createPaymentIntent')
        ->once()
        ->withArgs(function (
            User $user,
            int $amount,
            array $metadata,
            bool $setupFutureUsage,
            string $idempotencyKey,
        ) use ($customer): bool {
            return $user->is($customer)
                && $amount === 6000
                && $metadata['origin'] === InstallmentPaymentAttemptOrigin::Customer->value
                && $setupFutureUsage
                && str_starts_with($idempotencyKey, 'installment-payment-attempt-');
        })
        ->andReturnUsing(fn (
            User $user,
            int $amount,
            array $metadata,
        ): PaymentIntent => PaymentIntent::constructFrom([
            'id' => 'pi_combined',
            'client_secret' => 'pi_combined_secret_test',
            'status' => 'succeeded',
            'amount' => $amount,
            'currency' => 'usd',
            'customer' => $user->stripe_id,
            'payment_method' => 'pm_new',
            'metadata' => $metadata,
        ]));
    $this->app->instance(StripeServiceContract::class, $stripe);

    $paymentAttempt = app(CreateInstallmentPaymentAttempt::class)->handle(
        paymentPlan: $paymentPlan,
        installmentIds: [$oldest->id, $overdue->id],
        origin: InstallmentPaymentAttemptOrigin::Customer,
        initiatedBy: $customer,
        useForFuture: true,
    );
    $paymentAttempt = app(ProcessInstallmentPaymentAttempt::class)->handle($paymentAttempt);

    expect($paymentAttempt->status)->toBe(InstallmentPaymentAttemptStatus::Succeeded)
        ->and($paymentAttempt->total_amount)->toBe(6000)
        ->and($paymentAttempt->allocations)->toHaveCount(2)
        ->and($oldest->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and($oldest->stripe_payment_intent_id)->toBe('pi_combined')
        ->and($overdue->refresh()->status)->toBe(InstallmentStatus::Paid)
        ->and($overdue->stripe_payment_intent_id)->toBe('pi_combined')
        ->and($paymentPlan->refresh()->stripe_payment_method_id)->toBe('pm_new');

    expect($paymentAttempt->refresh()->success_email_status)->toBe(InstallmentPaymentAttemptEmailStatus::Queued)
        ->and($paymentAttempt->success_email_sent_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'payment-plan-catch-up-succeeded'
        && $mail->hasTo($customer->email));
});

it('prevents overlapping active attempts for an installment', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $customer->update(['stripe_id' => 'cus_overlap']);
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_overlap',
    ]);
    $installment = Installment::factory()->overdue()->create(['payment_plan_id' => $paymentPlan->id]);

    app(CreateInstallmentPaymentAttempt::class)->handle(
        paymentPlan: $paymentPlan,
        installmentIds: [$installment->id],
        origin: InstallmentPaymentAttemptOrigin::Customer,
        initiatedBy: $customer,
    );

    expect(fn () => app(CreateInstallmentPaymentAttempt::class)->handle(
        paymentPlan: $paymentPlan,
        installmentIds: [$installment->id],
        origin: InstallmentPaymentAttemptOrigin::Customer,
        initiatedBy: $customer,
    ))->toThrow(DomainException::class, 'already in progress');
});

it('synchronizes the Stripe customer used by a customer-initiated payment', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $customer->update(['stripe_id' => 'cus_current']);
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_stale',
    ]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 2500,
    ]);

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('createPaymentIntent')
        ->once()
        ->andReturnUsing(fn (
            User $user,
            int $amount,
            array $metadata,
        ): PaymentIntent => PaymentIntent::constructFrom([
            'id' => 'pi_current_customer',
            'status' => 'requires_payment_method',
            'amount' => $amount,
            'currency' => 'usd',
            'customer' => $user->stripe_id,
            'metadata' => $metadata,
        ]));
    $this->app->instance(StripeServiceContract::class, $stripe);

    $paymentAttempt = app(CreateInstallmentPaymentAttempt::class)->handle(
        paymentPlan: $paymentPlan,
        installmentIds: [$installment->id],
        origin: InstallmentPaymentAttemptOrigin::Customer,
        initiatedBy: $customer,
    );
    $paymentAttempt = app(ProcessInstallmentPaymentAttempt::class)->handle($paymentAttempt);

    expect([
        'attempt_customer' => $paymentAttempt->stripe_customer_id,
        'status' => $paymentAttempt->status->value,
        'failure_reason' => $paymentAttempt->failure_reason,
        'plan_customer' => $paymentPlan->refresh()->stripe_customer_id,
    ])->toBe([
        'attempt_customer' => 'cus_current',
        'status' => InstallmentPaymentAttemptStatus::RequiresPaymentMethod->value,
        'failure_reason' => null,
        'plan_customer' => 'cus_current',
    ]);
});

it('allows Stripe to create a customer while starting a customer-initiated payment', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $customer->update(['stripe_id' => null]);
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => null,
    ]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 2500,
    ]);

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('createPaymentIntent')
        ->once()
        ->andReturnUsing(fn (
            User $user,
            int $amount,
            array $metadata,
        ): PaymentIntent => PaymentIntent::constructFrom([
            'id' => 'pi_new_customer',
            'status' => 'requires_payment_method',
            'amount' => $amount,
            'currency' => 'usd',
            'customer' => 'cus_created',
            'metadata' => $metadata,
        ]));
    $this->app->instance(StripeServiceContract::class, $stripe);

    $paymentAttempt = app(CreateInstallmentPaymentAttempt::class)->handle(
        paymentPlan: $paymentPlan,
        installmentIds: [$installment->id],
        origin: InstallmentPaymentAttemptOrigin::Customer,
        initiatedBy: $customer,
    );
    $paymentAttempt = app(ProcessInstallmentPaymentAttempt::class)->handle($paymentAttempt);

    expect($paymentAttempt->stripe_customer_id)->toBe('cus_created')
        ->and($paymentPlan->refresh()->stripe_customer_id)->toBe('cus_created');
});

it('records a failed combined attempt only once when reconciliation repeats', function (): void {
    $paymentPlan = PaymentPlan::factory()->create(['stripe_customer_id' => 'cus_failed']);
    $first = Installment::factory()->failed()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 2000,
        'retry_count' => 1,
    ]);
    $second = Installment::factory()->failed()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 3,
        'amount' => 3000,
        'retry_count' => 1,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'origin' => InstallmentPaymentAttemptOrigin::Administrator,
        'status' => InstallmentPaymentAttemptStatus::Pending,
        'total_amount' => 5000,
        'stripe_customer_id' => 'cus_failed',
        'stripe_payment_method_id' => 'pm_declined',
    ]);
    $paymentAttempt->allocations()->createMany([
        ['installment_id' => $first->id, 'amount' => 2000],
        ['installment_id' => $second->id, 'amount' => 3000],
    ]);
    $paymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_failed_combined',
        'status' => 'requires_payment_method',
        'amount' => 5000,
        'currency' => 'usd',
        'customer' => 'cus_failed',
        'payment_method' => 'pm_declined',
        'metadata' => ['payment_attempt_id' => (string) $paymentAttempt->id],
        'last_payment_error' => [
            'message' => 'Your card was declined.',
            'decline_code' => 'insufficient_funds',
        ],
    ]);

    $finalizer = app(FinalizeInstallmentPaymentAttempt::class);
    $finalizer->handle($paymentAttempt, $paymentIntent);
    $finalizer->handle($paymentAttempt->refresh(), $paymentIntent);

    expect($paymentAttempt->refresh()->status)->toBe(InstallmentPaymentAttemptStatus::Failed)
        ->and($first->refresh()->retry_count)->toBe(2)
        ->and($second->refresh()->retry_count)->toBe(2)
        ->and($first->last_failure_code)->toBe('insufficient_funds');
});

it('does not let another customer create a payment attempt', function (): void {
    $owner = User::factory()->create(['stripe_id' => 'cus_owner']);
    $order = Order::factory()->completed()->create(['user_id' => $owner->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_owner',
    ]);
    $installment = Installment::factory()->overdue()->create(['payment_plan_id' => $paymentPlan->id]);

    expect(fn () => app(CreateInstallmentPaymentAttempt::class)->handle(
        paymentPlan: $paymentPlan,
        installmentIds: [$installment->id],
        origin: InstallmentPaymentAttemptOrigin::Customer,
        initiatedBy: auth()->user(),
    ))->toThrow(AuthorizationException::class);
});

it('queues a managed pay now link for the payment plan customer', function (): void {
    $customer = User::factory()->create(['email' => 'customer@example.com']);
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    Installment::factory()->overdue()->create(['payment_plan_id' => $paymentPlan->id]);

    expect(app(SendPaymentPlanPayNowLink::class)->handle($paymentPlan))->toBeTrue();

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail) use ($paymentPlan): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'payment-plan-pay-now-link'
            && $mail->hasTo('customer@example.com')
            && str_contains($rendered->html, (string) $paymentPlan->id)
            && str_contains($rendered->html, '/payment-plans/');
    });
});

it('never resurrects a cancelled installment when Stripe reports a late success', function (): void {
    $paymentPlan = PaymentPlan::factory()->create(['stripe_customer_id' => 'cus_late_success']);
    $installment = Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'status' => InstallmentStatus::Cancelled,
        'amount' => 3200,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'status' => InstallmentPaymentAttemptStatus::Pending,
        'total_amount' => 3200,
        'stripe_customer_id' => 'cus_late_success',
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => 3200,
    ]);
    $paymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_late_cancelled_success',
        'status' => 'succeeded',
        'amount' => 3200,
        'currency' => 'usd',
        'customer' => 'cus_late_success',
        'metadata' => ['payment_attempt_id' => (string) $paymentAttempt->id],
    ]);

    $result = app(FinalizeInstallmentPaymentAttempt::class)->handle($paymentAttempt, $paymentIntent);

    expect($result->status)->toBe(InstallmentPaymentAttemptStatus::Error)
        ->and($result->stripe_status)->toBe('succeeded')
        ->and($result->success_email_status)->toBeNull()
        ->and($installment->refresh()->status)->toBe(InstallmentStatus::Cancelled)
        ->and($installment->paid_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('preserves a cancelled attempt when a late failure or system error is recorded', function (): void {
    $paymentPlan = PaymentPlan::factory()->create(['stripe_customer_id' => 'cus_cancelled_failure']);
    $installment = Installment::factory()->failed(1)->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 2400,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'status' => InstallmentPaymentAttemptStatus::Cancelled,
        'total_amount' => 2400,
        'stripe_customer_id' => 'cus_cancelled_failure',
        'completed_at' => now(),
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => 2400,
    ]);
    $finalizer = app(FinalizeInstallmentPaymentAttempt::class);

    $finalizer->failWithoutPaymentIntent($paymentAttempt, 'Card declined', 'card_declined');
    $finalizer->recordOperationalError($paymentAttempt, 'Stripe configuration error.');

    expect($paymentAttempt->refresh()->status)->toBe(InstallmentPaymentAttemptStatus::Cancelled)
        ->and($paymentAttempt->failure_recorded_at)->toBeNull()
        ->and($paymentAttempt->failure_email_status)->toBeNull()
        ->and($installment->refresh()->status)->toBe(InstallmentStatus::Failed)
        ->and($installment->retry_count)->toBe(1);
    Mail::assertNothingQueued();
});

it('keeps recoverable Stripe API failures active without consuming an installment retry', function (): void {
    $paymentPlan = PaymentPlan::factory()->create(['stripe_customer_id' => 'cus_rate_limited']);
    $installment = Installment::factory()->failed()->create([
        'payment_plan_id' => $paymentPlan->id,
        'retry_count' => 1,
        'amount' => 2800,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'origin' => InstallmentPaymentAttemptOrigin::Administrator,
        'status' => InstallmentPaymentAttemptStatus::Pending,
        'total_amount' => 2800,
        'stripe_customer_id' => 'cus_rate_limited',
        'stripe_payment_method_id' => 'pm_rate_limited',
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => 2800,
    ]);
    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('chargePaymentMethod')
        ->once()
        ->andThrow(RateLimitException::factory('Too many requests.', 429));
    $this->app->instance(StripeServiceContract::class, $stripe);

    expect(fn () => app(ProcessInstallmentPaymentAttempt::class)->handle($paymentAttempt))
        ->toThrow(RateLimitException::class);

    expect($paymentAttempt->refresh()->status)->toBe(InstallmentPaymentAttemptStatus::Pending)
        ->and($paymentAttempt->failure_reason)->toContain('reconciled automatically')
        ->and($paymentAttempt->failure_email_status)->toBeNull()
        ->and($installment->refresh()->status)->toBe(InstallmentStatus::Failed)
        ->and($installment->retry_count)->toBe(1);
});

it('records deterministic Stripe API failures as operational errors', function (): void {
    $paymentPlan = PaymentPlan::factory()->create(['stripe_customer_id' => 'cus_auth_error']);
    $installment = Installment::factory()->failed()->create([
        'payment_plan_id' => $paymentPlan->id,
        'retry_count' => 1,
        'amount' => 2800,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'origin' => InstallmentPaymentAttemptOrigin::Administrator,
        'status' => InstallmentPaymentAttemptStatus::Pending,
        'total_amount' => 2800,
        'stripe_customer_id' => 'cus_auth_error',
        'stripe_payment_method_id' => 'pm_auth_error',
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => 2800,
    ]);
    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('chargePaymentMethod')
        ->once()
        ->andThrow(AuthenticationException::factory('Invalid API key.', 401));
    $this->app->instance(StripeServiceContract::class, $stripe);

    $result = app(ProcessInstallmentPaymentAttempt::class)->handle($paymentAttempt);

    expect($result->status)->toBe(InstallmentPaymentAttemptStatus::Error)
        ->and($result->failure_reason)->toContain('system configuration error')
        ->and($result->failure_email_status)->toBeNull()
        ->and($installment->refresh()->status)->toBe(InstallmentStatus::Failed)
        ->and($installment->retry_count)->toBe(1);

    Mail::assertNothingQueued();
});
