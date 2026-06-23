<?php

declare(strict_types=1);

use App\Enums\ProductType;
use App\Models\CreditGrant;
use App\Models\GiftCard;
use App\Models\Product;
use App\Models\User;

it('starts with zero available credit', function () {
    $user = User::factory()->create();

    expect($user->credit_balance)->toBe(0)
        ->and($user->availableRestrictedCreditBalance())->toBe(0);
});

it('derives unrestricted and restricted balances from active grants', function () {
    $user = User::factory()->create();
    CreditGrant::factory()->for($user)->amount(5000)->create();
    CreditGrant::factory()->for($user)->amount(3000)->restrictedTo(ProductType::Course)->create();
    CreditGrant::factory()->for($user)->amount(2000)->expired()->create();

    expect($user->credit_balance)->toBe(5000)
        ->and($user->availableRestrictedCreditBalance())->toBe(3000)
        ->and($user->creditGrants)->toHaveCount(3);
});

it('finds restricted credit applicable to a product', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $grant = CreditGrant::factory()->for($user)->amount(2500)->create([
        'has_product_restrictions' => true,
    ]);
    $grant->products()->attach($product);

    expect($user->getRestrictedCreditForProduct($product))->toBe(2500);
});

it('has gift card and transaction relationships', function () {
    $user = User::factory()->create();
    GiftCard::factory()->create(['purchased_by_user_id' => $user->id]);
    GiftCard::factory()->redeemed($user)->create();
    $grant = CreditGrant::factory()->for($user)->create();
    $grant->transactions()->create([
        'user_id' => $user->id,
        'amount' => $grant->initial_amount,
        'type' => App\Enums\CreditTransactionType::AdminGrant,
    ]);

    expect($user->giftCardsPurchased)->toHaveCount(1)
        ->and($user->giftCardsRedeemed)->toHaveCount(1)
        ->and($user->creditTransactions)->toHaveCount(1);
});
