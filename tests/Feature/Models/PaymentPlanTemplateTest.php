<?php

declare(strict_types=1);

use App\Enums\CourseSemester;
use App\Enums\PaymentPlanFrequency;
use App\Enums\ProductType;
use App\Models\Course;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;

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
        'product_type' => ProductType::Gear,
        'min_price' => 1000,
        'max_price' => 5000,
    ]);

    // Course at $75 should match Any + Course templates
    $templates = PaymentPlanTemplate::query()
        ->active()
        ->forProduct(Course::class, 7500)
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

it('matches course templates by selected semesters', function () {
    $fallCourse = Course::factory()->create(['semester' => CourseSemester::Fall]);
    $summerCourse = Course::factory()->create(['semester' => CourseSemester::Summer]);

    $fallProduct = Product::factory()->forCourse($fallCourse)->create(['price' => 5000]);
    $summerProduct = Product::factory()->forCourse($summerCourse)->create(['price' => 5000]);

    $template = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'course_semesters' => [CourseSemester::Fall->value],
        'min_price' => 1000,
        'max_price' => 10000,
    ]);

    expect($template->matchesProduct($fallProduct, 5000))->toBeTrue()
        ->and($template->matchesProduct($summerProduct, 5000))->toBeFalse();
});

it('allows all course semesters when no semester restriction is selected', function () {
    $course = Course::factory()->create(['semester' => CourseSemester::Summer]);
    $product = Product::factory()->forCourse($course)->create(['price' => 5000]);

    $template = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'course_semesters' => null,
        'min_price' => 1000,
        'max_price' => 10000,
    ]);

    expect($template->matchesProduct($product, 5000))->toBeTrue();
});
