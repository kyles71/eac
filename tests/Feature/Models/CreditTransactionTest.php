<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Models\CreditGrant;
use App\Models\GiftCard;
use App\Models\User;
use App\Services\CreditLedgerService;

it('belongs to its user, grant, source, and performer', function () {
    $recipient = User::factory()->create();
    $issuer = User::factory()->create();
    $giftCard = GiftCard::factory()->create();

    $grant = app(CreditLedgerService::class)->issue(
        recipient: $recipient,
        amount: 5000,
        description: 'Redeemed gift card',
        issuer: $issuer,
        source: $giftCard,
        transactionType: CreditTransactionType::GiftCardRedemption,
    );
    $transaction = $grant->transactions()->firstOrFail();

    expect($transaction->user->is($recipient))->toBeTrue()
        ->and($transaction->creditGrant->is($grant))->toBeTrue()
        ->and($transaction->reference->is($giftCard))->toBeTrue()
        ->and($transaction->performedBy->is($issuer))->toBeTrue();
});

it('formats signed amounts', function (int $amount, string $formatted) {
    $transaction = CreditGrant::factory()->create()->transactions()->create([
        'user_id' => User::factory()->create()->id,
        'amount' => $amount,
        'type' => CreditTransactionType::AdminAdjustment,
    ]);

    expect($transaction->formattedAmount())->toBe($formatted);
})->with([
    'positive' => [5000, '+$50.00'],
    'negative' => [-3000, '-$30.00'],
]);
