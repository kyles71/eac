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
})->with(['order.id', 'total_amount', 'number_of_installments', 'frequency', 'method']);
