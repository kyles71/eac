<?php

declare(strict_types=1);

use App\Actions\Store\RedeemGiftCard;
use App\Enums\CreditTransactionType;
use App\Enums\ProductType;
use App\Models\CreditGrant;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates restricted credit when gift card type has restrictions', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(5000)
        ->create();

    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(5000)->create();

    $action = new RedeemGiftCard;
    $action->handle($giftCard->code, $this->user);

    expect($this->user->refresh()->credit_balance)->toBe(0);

    $grant = CreditGrant::query()
        ->where('user_id', $this->user->id)
        ->first();

    expect($grant)->not->toBeNull()
        ->and($grant->source->is($giftCard))->toBeTrue()
        ->and($grant->restricted_to_product_type)->toBe(ProductType::Course)
        ->and($grant->remaining_amount)->toBe(5000);
});

it('adds to general credit when gift card type has no restrictions', function () {
    $giftCardType = GiftCardType::factory()->denomination(5000)->create();
    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(5000)->create();

    $action = new RedeemGiftCard;
    $action->handle($giftCard->code, $this->user);

    expect($this->user->refresh()->credit_balance)->toBe(5000);

    expect($this->user->creditGrants()->restricted()->count())->toBe(0);
});

it('adds to general credit when gift card has no type', function () {
    $giftCard = GiftCard::factory()->amount(3000)->create();

    $action = new RedeemGiftCard;
    $action->handle($giftCard->code, $this->user);

    expect($this->user->refresh()->credit_balance)->toBe(3000);
});

it('records a credit transaction for restricted redemption', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(5000)
        ->create();

    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(5000)->create();

    $action = new RedeemGiftCard;
    $action->handle($giftCard->code, $this->user);

    $transaction = $this->user->creditTransactions()->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->amount)->toBe(5000)
        ->and($transaction->type)->toBe(CreditTransactionType::GiftCardRedemption)
        ->and($transaction->creditGrant->restricted_to_product_type)->toBe(ProductType::Course);
});

it('marks gift card as redeemed for restricted redemption', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(5000)
        ->create();

    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(5000)->create();

    $action = new RedeemGiftCard;
    $result = $action->handle($giftCard->code, $this->user);

    expect($result->isRedeemed())->toBeTrue()
        ->and($result->redeemed_by_user_id)->toBe($this->user->id)
        ->and($result->remaining_amount)->toBe(0);
});
