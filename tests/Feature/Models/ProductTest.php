<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Product;

it('scopes available products', function () {
    $active = Product::factory()->create(['is_active' => true, 'price' => 5000]);
    $inactive = Product::factory()->inactive()->create();
    $zeroPriced = Product::factory()->create(['is_active' => true, 'price' => 0]);

    $results = Product::query()->available()->get();

    expect($results->pluck('id')->toArray())->toContain($active->id)
        ->and($results->pluck('id')->toArray())->not->toContain($inactive->id)
        ->and($results->pluck('id')->toArray())->not->toContain($zeroPriced->id);
});

it('formats price correctly', function () {
    $product = Product::factory()->create(['price' => 15099]);

    expect($product->formattedPrice())->toBe('$150.99');
});

it('morphs to a course', function () {
    $course = Course::factory()->create();
    $product = Product::factory()->forCourse($course)->create();

    expect($product->productable)->toBeInstanceOf(Course::class)
        ->and($product->productable->id)->toBe($course->id);
});

it('can be created without a productable', function () {
    $product = Product::factory()->create([
        'productable_type' => null,
        'productable_id' => null,
    ]);

    expect($product->productable)->toBeNull();
});
