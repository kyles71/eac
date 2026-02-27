<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Models\GiftCard;
use App\Models\User;

it('has a user relationship', function () {
    $user = User::factory()->create();

    $transaction = $user->adjustCredit(
        5000,
        CreditTransactionType::GiftCardRedemption,
        null,
        'Test credit',
    );

    expect($transaction->user->id)->toBe($user->id);
});

it('has a polymorphic reference', function () {
    $user = User::factory()->create();
    $giftCard = GiftCard::factory()->create();

    $transaction = $user->adjustCredit(
        5000,
        CreditTransactionType::GiftCardRedemption,
        $giftCard,
        'Redeemed gift card',
    );

    expect($transaction->reference)->toBeInstanceOf(GiftCard::class)
        ->and($transaction->reference->id)->toBe($giftCard->id);
});

it('formats positive amounts with a plus sign', function () {
    $user = User::factory()->create();
    $transaction = $user->adjustCredit(5000, CreditTransactionType::GiftCardRedemption);

    expect($transaction->formattedAmount())->toBe('+$50.00');
});

it('formats negative amounts with a minus sign', function () {
    $user = User::factory()->create(['credit_balance' => 10000]);
    $transaction = $user->adjustCredit(-3000, CreditTransactionType::CheckoutDebit);

    expect($transaction->formattedAmount())->toBe('-$30.00');
});

it('records the correct type', function () {
    $user = User::factory()->create();
    $transaction = $user->adjustCredit(5000, CreditTransactionType::AdminAdjustment, null, 'Manual adjustment');

    expect($transaction->type)->toBe(CreditTransactionType::AdminAdjustment)
        ->and($transaction->description)->toBe('Manual adjustment');
});
