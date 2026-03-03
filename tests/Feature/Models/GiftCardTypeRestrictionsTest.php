<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\Costume;
use App\Models\Course;
use App\Models\GiftCardType;
use App\Models\Product;

it('has no restrictions by default', function () {
    $giftCardType = GiftCardType::factory()->create();

    expect($giftCardType->hasRestrictions())->toBeFalse();
});

it('has restrictions when restricted_to_product_type is set', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->create();

    expect($giftCardType->hasRestrictions())->toBeTrue();
});

it('has restrictions when products are attached', function () {
    $giftCardType = GiftCardType::factory()->create();
    $product = Product::factory()->standalone()->create();
    $giftCardType->products()->attach($product);

    expect($giftCardType->hasRestrictions())->toBeTrue();
});

it('applies to all products when unrestricted', function () {
    $giftCardType = GiftCardType::factory()->create();

    $course = Course::factory()->create();
    $courseProduct = Product::factory()->forCourse($course)->create();
    $standaloneProduct = Product::factory()->standalone()->create();

    expect($giftCardType->appliesToProduct($courseProduct))->toBeTrue()
        ->and($giftCardType->appliesToProduct($standaloneProduct))->toBeTrue();
});

it('restricts by product type', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->create();

    $course = Course::factory()->create();
    $courseProduct = Product::factory()->forCourse($course)->create();
    $standaloneProduct = Product::factory()->standalone()->create();
    $costume = Costume::factory()->create();
    $costumeProduct = Product::factory()->forCostume($costume)->create();

    expect($giftCardType->appliesToProduct($courseProduct))->toBeTrue()
        ->and($giftCardType->appliesToProduct($standaloneProduct))->toBeFalse()
        ->and($giftCardType->appliesToProduct($costumeProduct))->toBeFalse();
});

it('restricts to specific products', function () {
    $giftCardType = GiftCardType::factory()->create();

    $course1 = Course::factory()->create();
    $product1 = Product::factory()->forCourse($course1)->create();
    $course2 = Course::factory()->create();
    $product2 = Product::factory()->forCourse($course2)->create();

    $giftCardType->products()->attach($product1);

    expect($giftCardType->appliesToProduct($product1))->toBeTrue()
        ->and($giftCardType->appliesToProduct($product2))->toBeFalse();
});

it('restricts by both product type and specific products', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->create();

    $course1 = Course::factory()->create();
    $courseProduct1 = Product::factory()->forCourse($course1)->create();
    $course2 = Course::factory()->create();
    $courseProduct2 = Product::factory()->forCourse($course2)->create();
    $standaloneProduct = Product::factory()->standalone()->create();

    // Restrict to specific course product only
    $giftCardType->products()->attach($courseProduct1);

    // courseProduct1 matches both type and specific product
    expect($giftCardType->appliesToProduct($courseProduct1))->toBeTrue()
        // courseProduct2 matches type but not specific product
        ->and($giftCardType->appliesToProduct($courseProduct2))->toBeFalse()
        // standalone doesn't match type
        ->and($giftCardType->appliesToProduct($standaloneProduct))->toBeFalse();
});

it('returns correct restriction summary for unrestricted', function () {
    $giftCardType = GiftCardType::factory()->create();

    expect($giftCardType->restrictionSummary())->toBe('Unrestricted');
});

it('returns correct restriction summary for product type restriction', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->create();

    expect($giftCardType->restrictionSummary())->toBe('Course products only');
});

it('returns correct restriction summary for specific product restrictions', function () {
    $giftCardType = GiftCardType::factory()->create();
    $product = Product::factory()->standalone()->create();
    $giftCardType->products()->attach($product);

    expect($giftCardType->restrictionSummary())->toBe('1 product');
});

it('returns correct restriction summary for combined restrictions', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->create();

    $course = Course::factory()->create();
    $product = Product::factory()->forCourse($course)->create();
    $giftCardType->products()->attach($product);

    expect($giftCardType->restrictionSummary())->toBe('Course products only, 1 product');
});
