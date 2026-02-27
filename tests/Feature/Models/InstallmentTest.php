<?php

declare(strict_types=1);

use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\PaymentPlan;

it('can be created with factory', function () {
    $installment = Installment::factory()->create();

    expect($installment)->toBeInstanceOf(Installment::class)
        ->and($installment->status)->toBe(InstallmentStatus::Pending)
        ->and($installment->retry_count)->toBe(0);
});

it('belongs to a payment plan', function () {
    $plan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->create(['payment_plan_id' => $plan->id]);

    expect($installment->paymentPlan->id)->toBe($plan->id);
});

it('can be marked as paid', function () {
    $installment = Installment::factory()->create();

    $installment->markPaid(stripePaymentIntentId: 'pi_test_123');
    $installment->refresh();

    expect($installment->status)->toBe(InstallmentStatus::Paid)
        ->and($installment->paid_at)->not->toBeNull()
        ->and($installment->stripe_payment_intent_id)->toBe('pi_test_123');
});

it('can be marked as paid with invoice id', function () {
    $installment = Installment::factory()->create();

    $installment->markPaid(stripeInvoiceId: 'inv_test_123');
    $installment->refresh();

    expect($installment->status)->toBe(InstallmentStatus::Paid)
        ->and($installment->stripe_invoice_id)->toBe('inv_test_123');
});

it('increments retry count on failure', function () {
    $installment = Installment::factory()->create(['retry_count' => 0]);

    $installment->markFailed();
    $installment->refresh();

    expect($installment->status)->toBe(InstallmentStatus::Failed)
        ->and($installment->retry_count)->toBe(1);
});

it('marks as overdue after 3 retries', function () {
    $installment = Installment::factory()->create(['retry_count' => 2]);

    $installment->markFailed();
    $installment->refresh();

    expect($installment->status)->toBe(InstallmentStatus::Overdue)
        ->and($installment->retry_count)->toBe(3);
});

it('scopes due installments', function () {
    Installment::factory()->create([
        'status' => InstallmentStatus::Pending,
        'due_date' => now()->subDay(),
    ]);
    Installment::factory()->create([
        'status' => InstallmentStatus::Pending,
        'due_date' => now()->addWeek(),
    ]);
    Installment::factory()->paid()->create();

    expect(Installment::query()->due()->count())->toBe(1);
});

it('scopes retryable installments', function () {
    Installment::factory()->failed(1)->create();
    Installment::factory()->failed(2)->create();
    Installment::factory()->overdue()->create(); // retry_count = 3, should not be retryable

    expect(Installment::query()->retryable()->count())->toBe(2);
});

it('scopes overdue installments', function () {
    Installment::factory()->overdue()->create();
    Installment::factory()->failed(1)->create();
    Installment::factory()->create();

    expect(Installment::query()->overdue()->count())->toBe(1);
});
