<?php

declare(strict_types=1);

use App\Models\GiftCard;
use App\Models\User;

it('belongs to a purchaser', function () {
    $user = User::factory()->create();
    $giftCard = GiftCard::factory()->create(['purchased_by_user_id' => $user->id]);

    expect($giftCard->purchasedBy->id)->toBe($user->id);
});

it('belongs to a redeemer when redeemed', function () {
    $purchaser = User::factory()->create();
    $redeemer = User::factory()->create();
    $giftCard = GiftCard::factory()->redeemed($redeemer)->create(['purchased_by_user_id' => $purchaser->id]);

    expect($giftCard->redeemedBy->id)->toBe($redeemer->id);
});

it('is redeemed when redeemed_at is set', function () {
    $giftCard = GiftCard::factory()->redeemed()->create();

    expect($giftCard->isRedeemed())->toBeTrue();
});

it('is not redeemed when redeemed_at is null', function () {
    $giftCard = GiftCard::factory()->create();

    expect($giftCard->isRedeemed())->toBeFalse();
});

it('is redeemable when active, not redeemed, and has balance', function () {
    $giftCard = GiftCard::factory()->amount(5000)->create();

    expect($giftCard->isRedeemable())->toBeTrue();
});

it('is not redeemable when inactive', function () {
    $giftCard = GiftCard::factory()->inactive()->create();

    expect($giftCard->isRedeemable())->toBeFalse();
});

it('is not redeemable when already redeemed', function () {
    $giftCard = GiftCard::factory()->redeemed()->create();

    expect($giftCard->isRedeemable())->toBeFalse();
});

it('formats initial amount in dollars', function () {
    $giftCard = GiftCard::factory()->amount(10000)->create();

    expect($giftCard->formattedInitialAmount())->toBe('$100.00');
});

it('formats remaining amount in dollars', function () {
    $giftCard = GiftCard::factory()->amount(5000)->create();

    expect($giftCard->formattedRemainingAmount())->toBe('$50.00');
});
