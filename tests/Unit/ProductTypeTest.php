<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\Costume;
use App\Models\Course;
use App\Models\GiftCardType;

it('maps Course productable_type to Course case', function () {
    expect(ProductType::fromProductableType(Course::class))->toBe(ProductType::Course);
});

it('maps GiftCardType productable_type to GiftCardType case', function () {
    expect(ProductType::fromProductableType(GiftCardType::class))->toBe(ProductType::GiftCardType);
});

it('maps Costume productable_type to Costume case', function () {
    expect(ProductType::fromProductableType(Costume::class))->toBe(ProductType::Costume);
});

it('maps null productable_type to Standalone case', function () {
    expect(ProductType::fromProductableType(null))->toBe(ProductType::Standalone);
});

it('throws for unrecognized productable_type', function () {
    ProductType::fromProductableType('App\\Models\\Unknown');
})->throws(InvalidArgumentException::class, 'Unrecognized productable type');

it('maps Course case to Course class', function () {
    expect(ProductType::Course->toProductableClass())->toBe(Course::class);
});

it('maps GiftCardType case to GiftCardType class', function () {
    expect(ProductType::GiftCardType->toProductableClass())->toBe(GiftCardType::class);
});

it('maps Costume case to Costume class', function () {
    expect(ProductType::Costume->toProductableClass())->toBe(Costume::class);
});

it('maps Standalone case to null', function () {
    expect(ProductType::Standalone->toProductableClass())->toBeNull();
});

it('throws when calling toProductableClass on Any', function () {
    ProductType::Any->toProductableClass();
})->throws(InvalidArgumentException::class, 'ProductType::Any cannot be mapped');

it('has correct labels', function (ProductType $type, string $label) {
    expect($type->getLabel())->toBe($label);
})->with([
    [ProductType::Any, 'Any'],
    [ProductType::Course, 'Course'],
    [ProductType::GiftCardType, 'Gift Card'],
    [ProductType::Costume, 'Costume'],
    [ProductType::Standalone, 'Standalone'],
]);
