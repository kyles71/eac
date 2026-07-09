<?php

declare(strict_types=1);

use App\Enums\InstallmentStatus;
use App\Filament\Admin\Resources\PaymentPlans\Pages\ListPaymentPlans;
use App\Filament\Admin\Resources\PaymentPlans\Pages\ViewPaymentPlan;
use App\Models\Installment;
use App\Models\PaymentPlan;
use Filament\Facades\Filament;

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

it('has required table columns', function (string $column) {
    livewire(ListPaymentPlans::class)
        ->assertTableColumnExists($column);
})->with([
    'order.user.full_name',
    'order.id',
    'payment_status',
    'installment_progress',
    'next_due_date',
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
        ->assertTableColumnStateSet('paid_amount', 4000, $plan)
        ->assertTableColumnStateSet('remaining', 6000, $plan);
});

it('has payment plan status and frequency filters', function () {
    livewire(ListPaymentPlans::class)
        ->assertTableFilterExists('payment_status')
        ->assertTableFilterExists('frequency');
});
