<?php

declare(strict_types=1);

use App\Enums\PaymentPlanFrequency;
use App\Enums\ProductType;
use App\Models\PaymentPlanTemplate;

it('can be created with factory', function () {
    $template = PaymentPlanTemplate::factory()->create();

    expect($template)->toBeInstanceOf(PaymentPlanTemplate::class)
        ->and($template->id)->toBeInt()
        ->and($template->name)->toBeString()
        ->and($template->product_type)->toBe(ProductType::Any)
        ->and($template->frequency)->toBe(PaymentPlanFrequency::Monthly)
        ->and($template->is_active)->toBeTrue();
});

it('scopes active templates', function () {
    PaymentPlanTemplate::factory()->create(['is_active' => true]);
    PaymentPlanTemplate::factory()->create(['is_active' => false]);

    expect(PaymentPlanTemplate::query()->active()->count())->toBe(1);
});

it('scopes for product by type and price', function () {
    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Any,
        'min_price' => 1000,
        'max_price' => 20000,
    ]);
    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'min_price' => 5000,
        'max_price' => 10000,
    ]);
    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Costume,
        'min_price' => 1000,
        'max_price' => 5000,
    ]);

    // Course at $75 should match Any + Course templates
    $templates = PaymentPlanTemplate::query()
        ->active()
        ->forProduct(App\Models\Course::class, 7500)
        ->get();

    expect($templates)->toHaveCount(2);
});

it('scopes for product excludes out-of-range templates', function () {
    PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Any,
        'min_price' => 10000,
        'max_price' => 20000,
    ]);

    $templates = PaymentPlanTemplate::query()
        ->active()
        ->forProduct(null, 5000)
        ->get();

    expect($templates)->toHaveCount(0);
});

it('calculates installment amounts correctly', function () {
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
    ]);

    $amounts = $template->installmentAmounts(10000);

    expect($amounts['first'])->toBe(3334)
        ->and($amounts['remaining'])->toBe(3333)
        ->and($amounts['first'] + ($amounts['remaining'] * 2))->toBe(10000);
});

it('calculates installment amounts with no remainder', function () {
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
    ]);

    $amounts = $template->installmentAmounts(10000);

    expect($amounts['first'])->toBe(2500)
        ->and($amounts['remaining'])->toBe(2500);
});
