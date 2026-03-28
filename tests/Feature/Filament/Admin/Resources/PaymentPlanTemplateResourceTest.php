<?php

declare(strict_types=1);

use App\Enums\PaymentPlanFrequency;
use App\Enums\ProductType;
use App\Filament\Admin\Resources\PaymentPlanTemplates\Pages\ListPaymentPlanTemplates;
use App\Models\PaymentPlanTemplate;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('can render the payment plan templates index page', function () {
    livewire(ListPaymentPlanTemplates::class)
        ->assertOk();
});

it('can list payment plan templates', function () {
    $templates = PaymentPlanTemplate::factory(3)->create();

    livewire(ListPaymentPlanTemplates::class)
        ->loadTable()
        ->assertCanSeeTableRecords($templates);
});

it('can search templates by name', function () {
    $template1 = PaymentPlanTemplate::factory()->create(['name' => 'Monthly 3-Pay']);
    $template2 = PaymentPlanTemplate::factory()->create(['name' => 'Weekly 6-Pay']);

    livewire(ListPaymentPlanTemplates::class)
        ->loadTable()
        ->searchTable('3-Pay')
        ->assertCanSeeTableRecords([$template1])
        ->assertCanNotSeeTableRecords([$template2]);
});

it('can create a payment plan template', function () {
    livewire(ListPaymentPlanTemplates::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Test Template',
            'product_type' => ProductType::Any->value,
            'min_price' => 5000,
            'max_price' => 50000,
            'number_of_installments' => 3,
            'frequency' => PaymentPlanFrequency::Monthly->value,
            'is_active' => true,
        ])
        ->assertNotified();

    assertDatabaseHas('payment_plan_templates', [
        'name' => 'Test Template',
        'number_of_installments' => 3,
    ]);
});

it('requires name to create a template', function () {
    livewire(ListPaymentPlanTemplates::class)
        ->callAction(CreateAction::class, data: [
            'name' => '',
            'product_type' => ProductType::Any->value,
            'min_price' => 5000,
            'max_price' => 50000,
            'number_of_installments' => 3,
            'frequency' => PaymentPlanFrequency::Monthly->value,
        ])
        ->assertHasActionErrors(['name' => 'required']);
});

it('requires number of installments between 2 and 24', function () {
    livewire(ListPaymentPlanTemplates::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Test',
            'product_type' => ProductType::Any->value,
            'min_price' => 5000,
            'max_price' => 50000,
            'number_of_installments' => 1,
            'frequency' => PaymentPlanFrequency::Monthly->value,
        ])
        ->assertHasActionErrors(['number_of_installments']);
});

it('has required columns', function (string $column) {
    livewire(ListPaymentPlanTemplates::class)
        ->assertTableColumnExists($column);
})->with(['id', 'name', 'product_type', 'min_price', 'max_price', 'number_of_installments', 'frequency', 'is_active']);
