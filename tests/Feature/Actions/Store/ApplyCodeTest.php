<?php

declare(strict_types=1);

use App\Actions\Store\ApplyCode;
use App\Models\DiscountCode;
use App\Models\GiftCard;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('applies a valid discount code', function () {
    $discountCode = DiscountCode::factory()->percentage(20)->create();

    $action = new ApplyCode;
    $result = $action->handle($discountCode->code, $this->user, 10000);

    expect($result['type'])->toBe('discount')
        ->and($result['discountCode']->id)->toBe($discountCode->id);
});

it('redeems a valid gift card', function () {
    $giftCard = GiftCard::factory()->amount(5000)->create();

    $action = new ApplyCode;
    $result = $action->handle($giftCard->code, $this->user, 10000);

    expect($result['type'])->toBe('gift_card')
        ->and($result['giftCard']->id)->toBe($giftCard->id)
        ->and($this->user->refresh()->credit_balance)->toBe(5000);
});

it('throws for an empty code', function () {
    $action = new ApplyCode;
    $action->handle('', $this->user, 10000);
})->throws(InvalidArgumentException::class, 'Please enter a code.');

it('throws for a whitespace-only code', function () {
    $action = new ApplyCode;
    $action->handle('   ', $this->user, 10000);
})->throws(InvalidArgumentException::class, 'Please enter a code.');

it('throws for a code that does not match anything', function () {
    $action = new ApplyCode;
    $action->handle('DOESNOTEXIST', $this->user, 10000);
})->throws(InvalidArgumentException::class, 'Invalid code. Please check and try again.');

it('prioritises discount code over gift card when both share the same code', function () {
    $code = 'SHARED_CODE_123';

    $discountCode = DiscountCode::factory()->percentage(10)->create(['code' => $code]);
    GiftCard::factory()->amount(5000)->create(['code' => $code]);

    $action = new ApplyCode;
    $result = $action->handle($code, $this->user, 10000);

    expect($result['type'])->toBe('discount')
        ->and($result['discountCode']->id)->toBe($discountCode->id)
        ->and($this->user->refresh()->credit_balance)->toBe(0);
});

it('passes through discount code validation errors', function () {
    $discountCode = DiscountCode::factory()->inactive()->create();

    $action = new ApplyCode;
    $action->handle($discountCode->code, $this->user, 10000);
})->throws(InvalidArgumentException::class, 'This discount code is no longer valid.');

it('passes through gift card validation errors', function () {
    $giftCard = GiftCard::factory()->redeemed()->create();

    $action = new ApplyCode;
    $action->handle($giftCard->code, $this->user, 10000);
})->throws(InvalidArgumentException::class, 'This gift card has already been redeemed.');

it('passes product ids to discount code validation', function () {
    $discountCode = DiscountCode::factory()->create();
    $product = App\Models\Product::factory()->create();
    $discountCode->products()->attach($product);

    $otherProduct = App\Models\Product::factory()->create();

    $action = new ApplyCode;
    $action->handle($discountCode->code, $this->user, 10000, [$otherProduct->id]);
})->throws(InvalidArgumentException::class, 'This discount code does not apply to any items in your cart.');
