<?php

declare(strict_types=1);

use App\Actions\Store\MarkInstallmentsPaid;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Enums\InstallmentStatus;
use App\Filament\Actions\AdjustPaymentPlanDueDatesAction;
use App\Filament\Actions\RetryPaymentPlanAction;
use App\Filament\Actions\SendPaymentPlanPayNowLinkAction;
use App\Filament\Admin\Resources\PaymentPlans\Pages\ListPaymentPlans;
use App\Filament\Admin\Resources\PaymentPlans\Pages\ViewPaymentPlan;
use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Models\Installment;
use App\Models\InstallmentDueDateAdjustment;
use App\Models\InstallmentPaymentAttempt;
use App\Models\PaymentPlan;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the payment plans index page', function () {
    livewire(ListPaymentPlans::class)
        ->assertOk();
});

it('can list payment plans', function () {
    $plans = PaymentPlan::factory(3)->create();

    livewire(ListPaymentPlans::class)
        ->loadTable()
        ->assertCanSeeTableRecords($plans);
});

it('can view a payment plan', function () {
    $plan = PaymentPlan::factory()->create();
    Installment::factory(3)->create(['payment_plan_id' => $plan->id]);

    livewire(ViewPaymentPlan::class, [
        'record' => $plan->id,
    ])
        ->assertOk();
});

it('shows payment attempt history on the payment plan', function (): void {
    $plan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $plan->id,
        'installment_number' => 2,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $plan->id,
        'stripe_payment_intent_id' => 'pi_admin_history',
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);

    livewire(ViewPaymentPlan::class, ['record' => $plan->id])
        ->assertOk()
        ->assertSee('Payment Attempt History')
        ->assertSee('pi_admin_history');
});

it('can mark an installment as paid via header action', function () {
    $plan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    livewire(ViewPaymentPlan::class, [
        'record' => $plan->id,
    ])
        ->callAction('markInstallmentPaid', data: [
            'installment_ids' => [$installment->id],
        ]);

    expect($installment->refresh()->status)->toBe(InstallmentStatus::Paid);
});

it('cannot manually mark an installment paid while a payment attempt is active', function (): void {
    $paymentPlan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'status' => InstallmentStatus::Pending,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'status' => InstallmentPaymentAttemptStatus::Processing,
        'total_amount' => $installment->amount,
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);

    expect(fn () => app(MarkInstallmentsPaid::class)->handle($paymentPlan, [$installment->id]))
        ->toThrow(DomainException::class, 'already in progress');

    expect($installment->refresh()->status)->toBe(InstallmentStatus::Pending);

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->assertActionHidden('markInstallmentPaid');
});

it('exposes and enforces the adjust due dates permission', function (): void {
    expect(FilamentShield::getResourcePolicyActionsWithPermissions(PaymentPlanResource::class))
        ->toHaveKey('adjustDueDates', 'AdjustDueDates:PaymentPlan');

    $paymentPlan = PaymentPlan::factory()->create();
    Installment::factory()->create(['payment_plan_id' => $paymentPlan->id]);
    $unauthorizedUser = User::factory()->create();
    $unauthorizedUser->givePermissionTo(['ViewAny:PaymentPlan', 'View:PaymentPlan']);
    $this->actingAs($unauthorizedUser);

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->assertActionHidden('adjustPaymentPlanDueDates');
});

it('exposes retry and payment link actions only for missed installments', function (): void {
    expect(FilamentShield::getResourcePolicyActionsWithPermissions(PaymentPlanResource::class))
        ->toHaveKey('retryPayment', 'RetryPayment:PaymentPlan')
        ->toHaveKey('sendPaymentLink', 'SendPaymentLink:PaymentPlan');

    $paymentPlan = PaymentPlan::factory()->create();
    Installment::factory()->overdue()->create(['payment_plan_id' => $paymentPlan->id]);

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->assertActionVisible(RetryPaymentPlanAction::class)
        ->assertActionVisible(SendPaymentPlanPayNowLinkAction::class);

    $unauthorizedUser = User::factory()->create();
    $unauthorizedUser->givePermissionTo(['ViewAny:PaymentPlan', 'View:PaymentPlan']);
    $this->actingAs($unauthorizedUser);

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->assertActionHidden(RetryPaymentPlanAction::class)
        ->assertActionHidden(SendPaymentPlanPayNowLinkAction::class);
});

it('hides retry and payment link actions when the only missed installment is already active', function (): void {
    $paymentPlan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->overdue()->create(['payment_plan_id' => $paymentPlan->id]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'status' => InstallmentPaymentAttemptStatus::RequiresAction,
        'total_amount' => $installment->amount,
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->assertActionHidden(RetryPaymentPlanAction::class)
        ->assertActionHidden(SendPaymentPlanPayNowLinkAction::class);
});

it('hides retry and payment link actions for cancelled orders', function (): void {
    $paymentPlan = PaymentPlan::factory()->create();
    Installment::factory()->overdue()->create(['payment_plan_id' => $paymentPlan->id]);
    $paymentPlan->order()->update(['status' => App\Enums\OrderStatus::Cancelled]);

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->assertActionHidden(RetryPaymentPlanAction::class)
        ->assertActionHidden(SendPaymentPlanPayNowLinkAction::class)
        ->assertActionHidden(AdjustPaymentPlanDueDatesAction::class);
});

it('warns before saving without email and requires explicit confirmation', function (): void {
    Mail::fake();
    $this->travelTo('2026-08-08 12:00:00');
    $paymentPlan = PaymentPlan::factory()->create();
    Installment::factory()->paid()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 1,
        'due_date' => '2026-08-01',
    ]);
    $installment = Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'due_date' => '2026-08-10',
    ]);

    app(ManagedTemplateRepository::class)->saveOverride('payment-plan-schedule-adjusted', [
        'is_active' => false,
    ]);

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->mountAction(AdjustPaymentPlanDueDatesAction::class)
        ->assertMountedActionModalSee('Customer email unavailable')
        ->assertMountedActionModalSee('The payment schedule adjustment email is disabled.')
        ->assertMountedActionModalSee('Save without emailing the customer')
        ->assertMountedActionModalSee('Monday, August 10, 2026')
        ->fillForm([
            'installments' => [[
                'installment_id' => $installment->id,
                'due_date' => '2026-08-08',
            ]],
            'reason' => 'Keep this reason in the open modal.',
        ])
        ->assertHasActionErrors(['installments.0.due_date'])
        ->assertActionDataSet([
            'reason' => 'Keep this reason in the open modal.',
        ])
        ->assertActionMounted()
        ->fillForm([
            'installments' => [[
                'installment_id' => $installment->id,
                'due_date' => '2026-08-12',
            ]],
            'reason' => 'Keep this reason in the open modal.',
        ])
        ->assertHasNoActionErrors(['installments.0.due_date'])
        ->assertActionDataSet([
            'reason' => 'Keep this reason in the open modal.',
        ])
        ->unmountAction();

    expect($installment->refresh()->due_date->toDateString())->toBe('2026-08-10')
        ->and(InstallmentDueDateAdjustment::query()->count())->toBe(0);

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->callAction(AdjustPaymentPlanDueDatesAction::class, data: [
            'installments' => [[
                'installment_id' => $installment->id,
                'due_date' => '2026-08-08',
            ]],
            'reason' => 'Invalid past date.',
            'confirm_without_email' => true,
        ])
        ->assertHasActionErrors([
            'installments.0.due_date' => 'after_or_equal',
        ]);

    expect($installment->refresh()->due_date->toDateString())->toBe('2026-08-10')
        ->and(InstallmentDueDateAdjustment::query()->count())->toBe(0);

    $data = [
        'installments' => [[
            'installment_id' => $installment->id,
            'due_date' => '2026-08-12',
        ]],
        'reason' => 'Requested by the customer.',
        'confirm_without_email' => false,
    ];

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->callAction(AdjustPaymentPlanDueDatesAction::class, data: $data)
        ->assertHasActionErrors(['confirm_without_email' => 'accepted']);

    expect($installment->refresh()->due_date->toDateString())->toBe('2026-08-10')
        ->and(InstallmentDueDateAdjustment::query()->count())->toBe(0);

    $data['confirm_without_email'] = true;

    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->callAction(AdjustPaymentPlanDueDatesAction::class, data: $data)
        ->assertHasNoActionErrors()
        ->assertNotified('1 installment due date updated')
        ->assertSee('Aug 12, 2026')
        ->assertSee('Requested by the customer.');

    expect($installment->refresh()->due_date->toDateString())->toBe('2026-08-12')
        ->and(InstallmentDueDateAdjustment::query()->value('customer_notification_status'))->toBe('Skipped');

    Mail::assertNothingQueued();
});

it('displays installment cards and a contained due date adjustment table', function (): void {
    $paymentPlan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
    ]);
    InstallmentDueDateAdjustment::factory()->create([
        'installment_id' => $installment->id,
    ]);

    $component = livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id]);

    $component
        ->assertSchemaComponentExists(
            'installments',
            'infolist',
            fn (RepeatableEntry $entry): bool => ! $entry->isTable()
                && $entry->isContained()
                && $entry->getColumns('lg') === 3,
        )
        ->assertSchemaComponentExists(
            'dueDateAdjustments',
            'infolist',
            fn (RepeatableEntry $entry): bool => $entry->isTable()
                && count($entry->getTableColumns() ?? []) === 8
                && str_contains((string) ($entry->getExtraAttributes()['style'] ?? ''), 'overflow-x: auto')
                && $entry->getContainer()->getParentComponent()?->getColumnSpan('default') === 'full',
        );
});

it('keeps the adjustment modal and its data open after a domain validation failure', function (): void {
    $this->travelTo('2026-08-08 12:00:00');
    $paymentPlan = PaymentPlan::factory()->create();
    $secondInstallment = Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'due_date' => '2026-08-10',
    ]);
    $thirdInstallment = Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 3,
        'due_date' => '2026-08-20',
    ]);
    livewire(ViewPaymentPlan::class, ['record' => $paymentPlan->id])
        ->mountAction(AdjustPaymentPlanDueDatesAction::class)
        ->fillForm(function (array $state): array {
            foreach (array_keys($state['installments']) as $installmentKey) {
                $state['installments'][$installmentKey]['due_date'] = '2026-08-12';
            }

            $state['reason'] = 'Keep this entered reason.';

            return $state;
        })
        ->callMountedAction()
        ->assertActionHalted()
        ->assertNotified('Could not adjust due dates')
        ->assertActionDataSet([
            'reason' => 'Keep this entered reason.',
        ]);

    expect($secondInstallment->refresh()->due_date->toDateString())->toBe('2026-08-10')
        ->and($thirdInstallment->refresh()->due_date->toDateString())->toBe('2026-08-20')
        ->and(InstallmentDueDateAdjustment::query()->count())->toBe(0);
});

it('has required table columns', function (string $column) {
    livewire(ListPaymentPlans::class)
        ->assertTableColumnExists($column);
})->with([
    'order.user.full_name',
    'order.id',
    'payment_status',
    'installment_progress',
    'next_due_date',
    'next_payment_amount',
    'total_amount',
    'paid_amount',
    'remaining',
    'frequency',
]);

it('shows payment status and installment progress without raw plan ids', function () {
    $plan = PaymentPlan::factory()->create(['total_amount' => 10000]);
    Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'installment_number' => 1,
        'amount' => 4000,
    ]);
    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'installment_number' => 2,
        'amount' => 6000,
        'status' => InstallmentStatus::Pending,
        'due_date' => '2026-08-01',
    ]);

    livewire(ListPaymentPlans::class)
        ->loadTable()
        ->assertTableColumnDoesNotExist('id')
        ->assertTableColumnStateSet('payment_status', 'Active', $plan)
        ->assertTableColumnStateSet('installment_progress', '1 / 2 paid', $plan)
        ->assertTableColumnStateSet('next_payment_amount', 6000, $plan)
        ->assertTableColumnStateSet('paid_amount', 4000, $plan)
        ->assertTableColumnStateSet('remaining', 6000, $plan)
        ->assertTableColumnDoesNotExist('number_of_installments');
});

it('has payment plan status and frequency filters', function () {
    livewire(ListPaymentPlans::class)
        ->assertTableFilterExists('payment_status')
        ->assertTableFilterExists('frequency');
});
