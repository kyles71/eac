<?php

declare(strict_types=1);

use App\Models\Gear;
use App\Models\Product;

it('can create gear', function () {
    $gear = Gear::factory()->create(['name' => 'Competition Jacket']);

    expect($gear->name)->toBe('Competition Jacket');
});

it('has a morphMany products relationship', function () {
    $gear = Gear::factory()->create();
    $products = Product::factory(2)->forGear($gear)->create();

    expect($gear->products)->toHaveCount(2)
        ->and($gear->products->modelKeys())->toBe($products->modelKeys());
});
