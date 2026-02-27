<?php

declare(strict_types=1);

use App\Actions\Store\RedeemGiftCard;
use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\GiftCard;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('redeems a valid gift card and credits the user', function () {
    $giftCard = GiftCard::factory()->amount(5000)->create();

    $action = new RedeemGiftCard;
    $result = $action->handle($giftCard->code, $this->user);

    expect($result->isRedeemed())->toBeTrue()
        ->and($result->redeemed_by_user_id)->toBe($this->user->id)
        ->and($result->remaining_amount)->toBe(0);

    expect($this->user->refresh()->credit_balance)->toBe(5000);
});

it('creates a credit transaction when redeeming', function () {
    $giftCard = GiftCard::factory()->amount(10000)->create();

    $action = new RedeemGiftCard;
    $action->handle($giftCard->code, $this->user);

    $transaction = CreditTransaction::query()
        ->where('user_id', $this->user->id)
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->amount)->toBe(10000)
        ->and($transaction->type)->toBe(CreditTransactionType::GiftCardRedemption)
        ->and($transaction->reference_type)->toBe(GiftCard::class)
        ->and($transaction->reference_id)->toBe($giftCard->id);
});

it('fails when gift card code does not exist', function () {
    $action = new RedeemGiftCard;
    $action->handle('INVALID_CODE', $this->user);
})->throws(InvalidArgumentException::class, 'Gift card not found.');

it('fails when gift card is inactive', function () {
    $giftCard = GiftCard::factory()->inactive()->create();

    $action = new RedeemGiftCard;
    $action->handle($giftCard->code, $this->user);
})->throws(InvalidArgumentException::class, 'This gift card has been deactivated.');

it('fails when gift card is already redeemed', function () {
    $giftCard = GiftCard::factory()->redeemed()->create();

    $action = new RedeemGiftCard;
    $action->handle($giftCard->code, $this->user);
})->throws(InvalidArgumentException::class, 'This gift card has already been redeemed.');

it('fails when gift card has no remaining balance', function () {
    $giftCard = GiftCard::factory()->create([
        'remaining_amount' => 0,
        'redeemed_at' => null,
    ]);

    $action = new RedeemGiftCard;
    $action->handle($giftCard->code, $this->user);
})->throws(InvalidArgumentException::class, 'This gift card has no remaining balance.');
