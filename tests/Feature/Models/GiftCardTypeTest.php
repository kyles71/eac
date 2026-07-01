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

it('formats custom amount minimums', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();

    expect($giftCardType->minimumCustomAmount())->toBe(500)
        ->and($giftCardType->suggestedCustomAmount())->toBe(5000)
        ->and($giftCardType->formattedMinimumCustomAmount())->toBe('$5.00');
});
