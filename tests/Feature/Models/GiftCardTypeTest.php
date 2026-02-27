<?php

declare(strict_types=1);

use App\Models\GiftCardType;
use App\Models\Product;

it('has a morphOne product relationship', function () {
    $giftCardType = GiftCardType::factory()->create();
    $product = Product::factory()->forGiftCardType($giftCardType)->create();

    expect($giftCardType->product->id)->toBe($product->id);
});

it('formats denomination in dollars', function () {
    $giftCardType = GiftCardType::factory()->denomination(5000)->create();

    expect($giftCardType->formattedDenomination())->toBe('$50.00');
});

it('formats zero denomination as Custom', function () {
    $giftCardType = GiftCardType::factory()->custom()->create();

    expect($giftCardType->formattedDenomination())->toBe('Custom');
});
