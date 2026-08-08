<?php

declare(strict_types=1);

use App\Actions\Store\AdjustPaymentPlanDueDates;
use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\InstallmentDueDateAdjustment;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo('2026-08-08 12:00:00');
});

it('registers the customizable payment schedule adjustment email', function (): void {
    $definition = app(EmailTypeRegistry::class)->get('payment-plan-schedule-adjusted');

    expect($definition->category)->toBe('transactional')
        ->and(array_keys($definition->tokensByKey()))
        ->toContain('adjustment.reason', 'payment_plan.number', 'order.number')
        ->and(array_keys($definition->slotsByMergeTag()))
        ->toBe(['slot.revised-schedule']);
});

it('adjusts unpaid installments reactivates failures audits the change and emails the customer', function (): void {
    [$paymentPlan, $failedInstallment, $overdueInstallment] = paymentPlanAdjustmentFixture();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    $result = app(AdjustPaymentPlanDueDates::class)->handle(
        paymentPlan: $paymentPlan,
        adjustedBy: $actor,
        dueDates: [
            $failedInstallment->id => '2026-08-09',
            $overdueInstallment->id => '2026-08-25',
        ],
        reason: '  Moved for paycheck <Friday>.  ',
    );

    expect($result)->toMatchArray([
        'adjusted' => 2,
        'customer_notification_status' => 'Queued',
        'customer_notification_note' => null,
    ]);

    foreach ([$failedInstallment, $overdueInstallment] as $installment) {
        expect($installment->refresh()->status)->toBe(InstallmentStatus::Pending)
            ->and($installment->retry_count)->toBe(0)
            ->and($installment->last_attempted_at)->toBeNull()
            ->and($installment->last_payment_status)->toBeNull()
            ->and($installment->last_failure_reason)->toBeNull()
            ->and($installment->last_failure_code)->toBeNull()
            ->and($installment->past_due_notification_sent_at)->toBeNull()
            ->and($installment->stripe_payment_intent_id)->not->toBeNull();
    }

    $adjustments = InstallmentDueDateAdjustment::query()
        ->orderBy('installment_id')
        ->get();

    expect($adjustments)->toHaveCount(2)
        ->and($adjustments->pluck('adjustment_batch_uuid')->unique())->toHaveCount(1)
        ->and($adjustments->pluck('adjusted_by_user_id')->unique()->all())->toBe([$actor->id])
        ->and($adjustments->pluck('reason')->unique()->all())->toBe(['Moved for paycheck <Friday>.'])
        ->and($adjustments->pluck('customer_notification_status')->unique()->all())->toBe(['Queued'])
        ->and($adjustments->pluck('previous_status')->all())->toBe([
            InstallmentStatus::Failed,
            InstallmentStatus::Overdue,
        ]);

    Mail::assertQueued(ManagedMail::class, 1);
    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail) use ($paymentPlan): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'payment-plan-schedule-adjusted'
            && $mail->hasTo($paymentPlan->order->user->email)
            && $mail->usesMailer('transactional')
            && str_contains($rendered->subject, "order #{$paymentPlan->order_id}")
            && str_contains($rendered->html, 'Moved for paycheck &lt;Friday&gt;.')
            && str_contains($rendered->html, 'August 9, 2026')
            && str_contains($rendered->html, 'August 25, 2026')
            && str_contains($rendered->html, '12:01 AM Eastern');
    });
});

it('requires explicit confirmation before saving when customer email is unavailable', function (): void {
    [$paymentPlan, $failedInstallment, $overdueInstallment] = paymentPlanAdjustmentFixture();
    $actor = auth()->user();
    $dueDates = [
        $failedInstallment->id => '2026-08-12',
        $overdueInstallment->id => '2026-08-25',
    ];

    expect($actor)->toBeInstanceOf(User::class);

    app(ManagedTemplateRepository::class)->saveOverride('payment-plan-schedule-adjusted', [
        'is_active' => false,
    ]);

    expect(fn () => app(AdjustPaymentPlanDueDates::class)->handle(
        paymentPlan: $paymentPlan,
        adjustedBy: $actor,
        dueDates: $dueDates,
        reason: 'Requested by the customer.',
    ))->toThrow(DomainException::class, 'Confirm that the schedule should be saved without emailing the customer.');

    expect($failedInstallment->refresh()->due_date->toDateString())->toBe('2026-08-10')
        ->and($overdueInstallment->refresh()->due_date->toDateString())->toBe('2026-08-20')
        ->and(InstallmentDueDateAdjustment::query()->count())->toBe(0);

    $result = app(AdjustPaymentPlanDueDates::class)->handle(
        paymentPlan: $paymentPlan,
        adjustedBy: $actor,
        dueDates: $dueDates,
        reason: 'Requested by the customer.',
        confirmWithoutEmail: true,
    );

    expect($result['customer_notification_status'])->toBe('Skipped')
        ->and(InstallmentDueDateAdjustment::query()->count())->toBe(2)
        ->and(InstallmentDueDateAdjustment::query()->pluck('customer_notification_status')->unique()->all())
        ->toBe(['Skipped'])
        ->and(InstallmentDueDateAdjustment::query()->pluck('customer_notification_note')->unique()->all())
        ->toBe(['The payment schedule adjustment email is disabled.']);

    Mail::assertNothingQueued();
});

it('allows an unpaid installment to move before the historical due date of a paid installment', function (): void {
    [$paymentPlan, $failedInstallment, $overdueInstallment] = paymentPlanAdjustmentFixture();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    $paymentPlan->installments()
        ->where('installment_number', 1)
        ->update(['due_date' => '2026-09-01']);

    $result = app(AdjustPaymentPlanDueDates::class)->handle(
        paymentPlan: $paymentPlan,
        adjustedBy: $actor,
        dueDates: [
            $failedInstallment->id => '2026-08-09',
            $overdueInstallment->id => '2026-08-25',
        ],
        reason: 'Move the next payment to tomorrow.',
    );

    expect($result['adjusted'])->toBe(2)
        ->and($failedInstallment->refresh()->due_date->toDateString())->toBe('2026-08-09')
        ->and($overdueInstallment->refresh()->due_date->toDateString())->toBe('2026-08-25');
});

it('rejects invalid schedules without partial changes', function (): void {
    [$paymentPlan, $failedInstallment, $overdueInstallment] = paymentPlanAdjustmentFixture();
    $actor = auth()->user();

    expect($actor)->toBeInstanceOf(User::class);

    expect(fn () => app(AdjustPaymentPlanDueDates::class)->handle(
        $paymentPlan,
        $actor,
        [$failedInstallment->id => '2026-08-08', $overdueInstallment->id => '2026-08-25'],
        'Invalid past date.',
    ))->toThrow(InvalidArgumentException::class, 'tomorrow or later');

    expect(fn () => app(AdjustPaymentPlanDueDates::class)->handle(
        $paymentPlan,
        $actor,
        [$failedInstallment->id => '2026-08-15', $overdueInstallment->id => '2026-08-15'],
        'Invalid duplicate date.',
    ))->toThrow(InvalidArgumentException::class, 'strictly increasing');

    expect(fn () => app(AdjustPaymentPlanDueDates::class)->handle(
        $paymentPlan,
        $actor,
        [$failedInstallment->id => '2026-08-10', $overdueInstallment->id => '2026-08-20'],
        'No actual change.',
    ))->toThrow(DomainException::class, 'At least one installment due date must be changed.');

    expect(fn () => app(AdjustPaymentPlanDueDates::class)->handle(
        $paymentPlan,
        $actor,
        [$paymentPlan->installments()->firstOrFail()->id => '2026-08-09', $failedInstallment->id => '2026-08-12'],
        'Attempts to alter a paid installment.',
    ))->toThrow(InvalidArgumentException::class, 'every unpaid installment');

    expect($failedInstallment->refresh()->due_date->toDateString())->toBe('2026-08-10')
        ->and($failedInstallment->status)->toBe(InstallmentStatus::Failed)
        ->and($overdueInstallment->refresh()->due_date->toDateString())->toBe('2026-08-20')
        ->and($overdueInstallment->status)->toBe(InstallmentStatus::Overdue)
        ->and(InstallmentDueDateAdjustment::query()->count())->toBe(0);

    Mail::assertNothingQueued();
});

it('enforces adjustment authorization at the domain boundary', function (): void {
    [$paymentPlan, $failedInstallment, $overdueInstallment] = paymentPlanAdjustmentFixture();
    $unauthorizedUser = User::factory()->create();

    app(AdjustPaymentPlanDueDates::class)->handle(
        paymentPlan: $paymentPlan,
        adjustedBy: $unauthorizedUser,
        dueDates: [
            $failedInstallment->id => '2026-08-12',
            $overdueInstallment->id => '2026-08-25',
        ],
        reason: 'Unauthorized adjustment.',
    );
})->throws(AuthorizationException::class);

/** @return array{PaymentPlan, Installment, Installment} */
function paymentPlanAdjustmentFixture(): array
{
    $customer = User::factory()->create([
        'first_name' => 'Jamie',
        'last_name' => 'Dancer',
        'email' => 'jamie@example.com',
    ]);
    $order = Order::factory()->completed()->create([
        'user_id' => $customer->id,
        'total' => 15000,
    ]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'total_amount' => 15000,
        'number_of_installments' => 3,
    ]);

    Installment::factory()->paid()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 1,
        'amount' => 5000,
        'due_date' => '2026-08-01',
    ]);
    $failedInstallment = Installment::factory()->failed()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 5000,
        'due_date' => '2026-08-10',
        'last_attempted_at' => now()->subDay(),
        'last_payment_status' => 'requires_payment_method',
        'last_failure_reason' => 'Insufficient funds.',
        'last_failure_code' => 'insufficient_funds',
        'stripe_payment_intent_id' => 'pi_failed_adjustment',
    ]);
    $overdueInstallment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 3,
        'amount' => 5000,
        'due_date' => '2026-08-20',
        'last_attempted_at' => now()->subDay(),
        'last_payment_status' => 'requires_payment_method',
        'last_failure_reason' => 'Insufficient funds.',
        'last_failure_code' => 'insufficient_funds',
        'stripe_payment_intent_id' => 'pi_overdue_adjustment',
        'past_due_notification_sent_at' => now()->subDay(),
    ]);

    return [$paymentPlan, $failedInstallment, $overdueInstallment];
}
