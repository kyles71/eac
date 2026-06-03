<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Enums\PaymentPlanFrequency;
use App\Enums\ProductType;
use App\Filament\Admin\Resources\PaymentPlanTemplates\Pages\ListPaymentPlanTemplates;
use App\Models\Order;
use App\Models\PaymentPlanTemplate;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
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
            'min_price' => '50.00',
            'max_price' => '500.00',
            'number_of_installments' => 3,
            'frequency' => PaymentPlanFrequency::Monthly->value,
            'is_active' => true,
        ])
        ->assertNotified();

    assertDatabaseHas('payment_plan_templates', [
        'name' => 'Test Template',
        'min_price' => 5000,
        'max_price' => 50000,
        'number_of_installments' => 3,
    ]);
});

it('requires name to create a template', function () {
    livewire(ListPaymentPlanTemplates::class)
        ->callAction(CreateAction::class, data: [
            'name' => '',
            'product_type' => ProductType::Any->value,
            'min_price' => '50.00',
            'max_price' => '500.00',
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
            'min_price' => '50.00',
            'max_price' => '500.00',
            'number_of_installments' => 1,
            'frequency' => PaymentPlanFrequency::Monthly->value,
        ])
        ->assertHasActionErrors(['number_of_installments']);
});

it('shows course semester restrictions only for course templates', function () {
    livewire(ListPaymentPlanTemplates::class)
        ->mountAction(CreateAction::class)
        ->assertSchemaComponentHidden('course_semesters')
        ->fillForm([
            'product_type' => ProductType::Course->value,
        ])
        ->assertSchemaComponentVisible('course_semesters')
        ->fillForm([
            'product_type' => ProductType::Any->value,
        ])
        ->assertSchemaComponentHidden('course_semesters');
});

it('stores course semester restrictions when creating a course template', function () {
    livewire(ListPaymentPlanTemplates::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'Fall Course Plan',
            'product_type' => ProductType::Course->value,
            'course_semesters' => [CourseSemester::Fall->value],
            'min_price' => '50.00',
            'max_price' => '500.00',
            'number_of_installments' => 3,
            'frequency' => PaymentPlanFrequency::Monthly->value,
            'is_active' => true,
        ])
        ->assertNotified();

    $template = PaymentPlanTemplate::query()
        ->where('name', 'Fall Course Plan')
        ->firstOrFail();

    expect($template->course_semesters)->toBe([CourseSemester::Fall->value]);
});

it('can edit an unused payment plan template', function () {
    $template = PaymentPlanTemplate::factory()->create([
        'name' => 'Original Template',
        'min_price' => 5000,
        'max_price' => 50000,
    ]);

    livewire(ListPaymentPlanTemplates::class)
        ->callAction(TestAction::make(EditAction::class)->table($template), data: [
            'name' => 'Updated Template',
            'product_type' => ProductType::Any->value,
            'course_semesters' => null,
            'min_price' => '75.00',
            'max_price' => '600.00',
            'number_of_installments' => 4,
            'frequency' => PaymentPlanFrequency::Weekly->value,
            'is_active' => true,
        ])
        ->assertNotified();

    $template->refresh();

    expect($template->name)->toBe('Updated Template')
        ->and($template->min_price)->toBe(7500)
        ->and($template->max_price)->toBe(60000);
});

it('hides edit for a used payment plan template', function () {
    $template = PaymentPlanTemplate::factory()->create();

    Order::factory()->create([
        'payment_plan_template_id' => $template->id,
    ]);

    livewire(ListPaymentPlanTemplates::class)
        ->assertActionHidden(TestAction::make(EditAction::class)->table($template));
});

it('has required columns', function (string $column) {
    livewire(ListPaymentPlanTemplates::class)
        ->assertTableColumnExists($column);
})->with(['id', 'name', 'product_type', 'course_semesters', 'min_price', 'max_price', 'number_of_installments', 'frequency', 'is_active']);
