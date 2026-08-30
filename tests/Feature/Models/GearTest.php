<?php

declare(strict_types=1);

use App\Models\Gear;
use App\Models\Product;

it('can create gear', function () {
    $gear = Gear::factory()->create(['name' => 'Competition Jacket']);

    expect($gear->name)->toBe('Competition Jacket');
});

it('has a morphOne product relationship', function () {
    $gear = Gear::factory()->create();
    $product = Product::factory()->forGear($gear)->create();

    expect($gear->product->id)->toBe($product->id);
});
