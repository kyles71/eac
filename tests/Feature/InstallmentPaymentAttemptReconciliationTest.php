<?php

declare(strict_types=1);

use App\Actions\Store\ReconcileInstallmentPaymentAttempts;
use App\Actions\Store\SendInstallmentPaymentAttemptEmail;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentPaymentAttemptEmailStatus;
use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Enums\InstallmentStatus;
use App\Jobs\SendInstallmentPaymentAttemptResultEmail;
use App\Models\Installment;
use App\Models\InstallmentPaymentAttempt;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Stripe\Exception\ApiConnectionException;
use Stripe\PaymentIntent;

it('cancels an abandoned requires-action attempt after 24 hours', function (): void {
    [$paymentAttempt, $installment] = reconciliationAttempt(
        InstallmentPaymentAttemptStatus::RequiresAction,
        createdAt: now()->subDay()->subMinute(),
    );
    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('retrievePaymentIntent')
        ->once()
        ->with('pi_reconciliation')
        ->andReturn(reconciliationPaymentIntent($paymentAttempt, 'requires_action'));
    $stripe->shouldReceive('cancelPaymentIntent')
        ->once()
        ->with('pi_reconciliation')
        ->andReturn(reconciliationPaymentIntent($paymentAttempt, 'canceled'));
    $this->app->instance(StripeServiceContract::class, $stripe);

    $result = app(ReconcileInstallmentPaymentAttempts::class)->handle();

    expect($result)->toMatchArray(['processed' => 1, 'failed' => 0])
        ->and($paymentAttempt->refresh()->status)->toBe(InstallmentPaymentAttemptStatus::Cancelled)
        ->and($installment->refresh()->status)->toBe(InstallmentStatus::Failed);
});

it('keeps a younger requires-action attempt active', function (): void {
    [$paymentAttempt] = reconciliationAttempt(
        InstallmentPaymentAttemptStatus::RequiresAction,
        createdAt: now()->subHours(23),
    );
    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('retrievePaymentIntent')
        ->once()
        ->andReturn(reconciliationPaymentIntent($paymentAttempt, 'requires_action'));
    $stripe->shouldNotReceive('cancelPaymentIntent');
    $this->app->instance(StripeServiceContract::class, $stripe);

    app(ReconcileInstallmentPaymentAttempts::class)->handle();

    expect($paymentAttempt->refresh()->status)->toBe(InstallmentPaymentAttemptStatus::RequiresAction);
});

it('never expires a processing attempt', function (): void {
    [$paymentAttempt] = reconciliationAttempt(
        InstallmentPaymentAttemptStatus::Processing,
        createdAt: now()->subDays(2),
    );
    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('retrievePaymentIntent')
        ->once()
        ->andReturn(reconciliationPaymentIntent($paymentAttempt, 'processing'));
    $stripe->shouldNotReceive('cancelPaymentIntent');
    $this->app->instance(StripeServiceContract::class, $stripe);

    app(ReconcileInstallmentPaymentAttempts::class)->handle();

    expect($paymentAttempt->refresh()->status)->toBe(InstallmentPaymentAttemptStatus::Processing);
});

it('backfills a pending result email job for a terminal attempt', function (): void {
    Queue::fake();
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'status' => InstallmentPaymentAttemptStatus::Succeeded,
        'success_email_status' => InstallmentPaymentAttemptEmailStatus::Pending,
        'completed_at' => now(),
    ]);

    $result = app(ReconcileInstallmentPaymentAttempts::class)->handle();

    expect($result['emails_queued'])->toBe(1);
    Queue::assertPushed(
        SendInstallmentPaymentAttemptResultEmail::class,
        fn (SendInstallmentPaymentAttemptResultEmail $job): bool => $job->paymentAttemptId === $paymentAttempt->id
            && $job->successful,
    );
});

it('records a result email only after it is queued and does not duplicate it', function (): void {
    Mail::fake();
    [$paymentAttempt] = reconciliationAttempt(
        InstallmentPaymentAttemptStatus::Succeeded,
        createdAt: now(),
        stripeStatus: 'succeeded',
    );
    $paymentAttempt->update([
        'success_email_status' => InstallmentPaymentAttemptEmailStatus::Pending,
        'completed_at' => now(),
    ]);
    $job = new SendInstallmentPaymentAttemptResultEmail(
        $paymentAttempt->id,
        $paymentAttempt->idempotency_key,
        successful: true,
    );

    $job->handle(app(SendInstallmentPaymentAttemptEmail::class));
    $job->handle(app(SendInstallmentPaymentAttemptEmail::class));

    expect($paymentAttempt->refresh()->success_email_status)->toBe(InstallmentPaymentAttemptEmailStatus::Queued)
        ->and($paymentAttempt->success_email_processing_at)->toBeNull()
        ->and($paymentAttempt->success_email_sent_at)->not->toBeNull();
    Mail::assertQueued(ManagedMail::class, 1);
});

it('leaves a failed result email pending for a queue retry', function (): void {
    [$paymentAttempt] = reconciliationAttempt(
        InstallmentPaymentAttemptStatus::Succeeded,
        createdAt: now(),
        stripeStatus: 'succeeded',
    );
    $paymentAttempt->update([
        'success_email_status' => InstallmentPaymentAttemptEmailStatus::Pending,
        'completed_at' => now(),
    ]);
    Mail::shouldReceive('mailer')
        ->once()
        ->andThrow(new RuntimeException('Queue unavailable.'));
    $job = new SendInstallmentPaymentAttemptResultEmail(
        $paymentAttempt->id,
        $paymentAttempt->idempotency_key,
        successful: true,
    );

    expect(fn () => $job->handle(app(SendInstallmentPaymentAttemptEmail::class)))
        ->toThrow(RuntimeException::class, 'Queue unavailable.');

    expect($paymentAttempt->refresh()->success_email_status)->toBe(InstallmentPaymentAttemptEmailStatus::Pending)
        ->and($paymentAttempt->success_email_processing_at)->toBeNull()
        ->and($paymentAttempt->success_email_sent_at)->toBeNull();

    $job->failed(new RuntimeException('Retries exhausted.'));

    expect($paymentAttempt->refresh()->success_email_status)->toBe(InstallmentPaymentAttemptEmailStatus::Failed);
});

it('requeues an email claim abandoned by a terminated worker', function (): void {
    Queue::fake();
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'status' => InstallmentPaymentAttemptStatus::Succeeded,
        'success_email_status' => InstallmentPaymentAttemptEmailStatus::Processing,
        'success_email_processing_at' => now()->subMinutes(16),
        'completed_at' => now()->subMinutes(20),
    ]);

    $result = app(ReconcileInstallmentPaymentAttempts::class)->handle();

    expect($result['emails_queued'])->toBe(1)
        ->and($paymentAttempt->refresh()->success_email_status)->toBe(InstallmentPaymentAttemptEmailStatus::Pending)
        ->and($paymentAttempt->success_email_processing_at)->toBeNull();
    Queue::assertPushed(
        SendInstallmentPaymentAttemptResultEmail::class,
        fn (SendInstallmentPaymentAttemptResultEmail $job): bool => $job->paymentAttemptId === $paymentAttempt->id
            && $job->successful,
    );
});

it('returns a failing command exit when reconciliation encounters a Stripe outage', function (): void {
    [$paymentAttempt] = reconciliationAttempt(
        InstallmentPaymentAttemptStatus::Pending,
        createdAt: now()->subHour(),
        withPaymentIntent: false,
    );
    $paymentAttempt->update([
        'origin' => InstallmentPaymentAttemptOrigin::Administrator,
        'stripe_payment_method_id' => 'pm_reconciliation',
    ]);
    InstallmentPaymentAttempt::query()
        ->whereKey($paymentAttempt->id)
        ->toBase()
        ->update(['updated_at' => now()->subMinutes(3)]);
    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('chargePaymentMethod')
        ->once()
        ->andThrow(ApiConnectionException::factory('Stripe unavailable.'));
    $this->app->instance(StripeServiceContract::class, $stripe);

    $this->artisan('installments:reconcile-payment-attempts')
        ->expectsOutputToContain('1 failed')
        ->assertExitCode(1);
});

/**
 * @return array{InstallmentPaymentAttempt, Installment, User}
 */
function reconciliationAttempt(
    InstallmentPaymentAttemptStatus $status,
    DateTimeInterface $createdAt,
    ?string $stripeStatus = null,
    bool $withPaymentIntent = true,
): array {
    $user = User::factory()->create(['email' => 'reconciliation@example.com']);
    $order = Order::factory()->completed()->create(['user_id' => $user->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_reconciliation',
    ]);
    $installment = Installment::factory()->failed()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 2400,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'origin' => InstallmentPaymentAttemptOrigin::Customer,
        'status' => $status,
        'total_amount' => 2400,
        'stripe_payment_intent_id' => $withPaymentIntent ? 'pi_reconciliation' : null,
        'stripe_customer_id' => 'cus_reconciliation',
        'stripe_status' => $stripeStatus,
        'created_at' => $createdAt,
        'updated_at' => now()->subMinutes(3),
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => 2400,
    ]);

    return [$paymentAttempt, $installment, $user];
}

function reconciliationPaymentIntent(
    InstallmentPaymentAttempt $paymentAttempt,
    string $status,
): PaymentIntent {
    return PaymentIntent::constructFrom([
        'id' => 'pi_reconciliation',
        'status' => $status,
        'amount' => $paymentAttempt->total_amount,
        'currency' => 'usd',
        'customer' => $paymentAttempt->stripe_customer_id,
        'metadata' => ['payment_attempt_id' => (string) $paymentAttempt->id],
    ]);
}
