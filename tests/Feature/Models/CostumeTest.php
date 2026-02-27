<?php

declare(strict_types=1);

use App\Models\Costume;
use App\Models\Product;

it('can create a costume', function () {
    $costume = Costume::factory()->create(['name' => 'Swan Lake Tutu']);

    expect($costume->name)->toBe('Swan Lake Tutu');
});

it('has a morphOne product relationship', function () {
    $costume = Costume::factory()->create();
    $product = Product::factory()->forCostume($costume)->create();

    expect($costume->product->id)->toBe($product->id);
});
