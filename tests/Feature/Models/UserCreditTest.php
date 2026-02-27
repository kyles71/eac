<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\GiftCard;
use App\Models\User;

it('starts with zero credit balance', function () {
    $user = User::factory()->create();

    expect($user->refresh()->credit_balance)->toBe(0);
});

it('adjusts credit balance upward', function () {
    $user = User::factory()->create();

    $user->adjustCredit(5000, CreditTransactionType::GiftCardRedemption);

    expect($user->refresh()->credit_balance)->toBe(5000);
});

it('adjusts credit balance downward', function () {
    $user = User::factory()->create(['credit_balance' => 10000]);

    $user->adjustCredit(-3000, CreditTransactionType::CheckoutDebit);

    expect($user->refresh()->credit_balance)->toBe(7000);
});

it('creates a credit transaction when adjusting', function () {
    $user = User::factory()->create();
    $giftCard = GiftCard::factory()->create();

    $transaction = $user->adjustCredit(
        5000,
        CreditTransactionType::GiftCardRedemption,
        $giftCard,
        'Redeemed gift card',
    );

    expect($transaction)->toBeInstanceOf(CreditTransaction::class)
        ->and($transaction->amount)->toBe(5000)
        ->and($transaction->type)->toBe(CreditTransactionType::GiftCardRedemption)
        ->and($transaction->reference_type)->toBe(GiftCard::class)
        ->and($transaction->reference_id)->toBe($giftCard->id)
        ->and($transaction->description)->toBe('Redeemed gift card');
});

it('has gift cards purchased relationship', function () {
    $user = User::factory()->create();
    $giftCard = GiftCard::factory()->create(['purchased_by_user_id' => $user->id]);

    expect($user->giftCardsPurchased)->toHaveCount(1)
        ->and($user->giftCardsPurchased->first()->id)->toBe($giftCard->id);
});

it('has gift cards redeemed relationship', function () {
    $user = User::factory()->create();
    $giftCard = GiftCard::factory()->redeemed($user)->create();

    expect($user->giftCardsRedeemed)->toHaveCount(1)
        ->and($user->giftCardsRedeemed->first()->id)->toBe($giftCard->id);
});

it('has credit transactions relationship', function () {
    $user = User::factory()->create();
    $user->adjustCredit(5000, CreditTransactionType::GiftCardRedemption);
    $user->adjustCredit(3000, CreditTransactionType::AdminAdjustment);

    expect($user->creditTransactions)->toHaveCount(2);
});
