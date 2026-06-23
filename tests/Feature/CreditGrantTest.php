<?php

declare(strict_types=1);

use App\Enums\CreditGrantStatus;
use App\Enums\CreditTransactionType;
use App\Enums\ProductType;
use App\Models\Costume;
use App\Models\CreditGrant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CreditLedgerService;
use Carbon\CarbonImmutable;

it('issues an auditable grant', function () {
    $recipient = User::factory()->create();
    $issuer = User::factory()->create();

    $grant = app(CreditLedgerService::class)->issue(
        recipient: $recipient,
        amount: 5000,
        description: 'Registration correction',
        issuer: $issuer,
        expiresOn: CarbonImmutable::parse('2026-12-31', 'America/New_York'),
    );

    expect($grant->initial_amount)->toBe(5000)
        ->and($grant->remaining_amount)->toBe(5000)
        ->and($grant->description)->toBe('Registration correction')
        ->and($grant->granted_by_user_id)->toBe($issuer->id)
        ->and($grant->status())->toBe(CreditGrantStatus::Active)
        ->and($recipient->credit_balance)->toBe(5000);

    $transaction = $grant->transactions()->first();

    expect($transaction?->amount)->toBe(5000)
        ->and($transaction?->type)->toBe(CreditTransactionType::AdminGrant)
        ->and($transaction?->performed_by_user_id)->toBe($issuer->id);
});

it('applies product type and specific product restrictions together', function () {
    $user = User::factory()->create();
    $allowedCostume = Product::factory()->for(Costume::factory(), 'productable')->create();
    $otherCostume = Product::factory()->for(Costume::factory(), 'productable')->create();
    $grant = CreditGrant::factory()
        ->for($user)
        ->restrictedTo(ProductType::Costume)
        ->create(['has_product_restrictions' => true]);
    $grant->products()->attach($allowedCostume);

    expect($grant->appliesToProduct($allowedCostume))->toBeTrue()
        ->and($grant->appliesToProduct($otherCostume))->toBeFalse()
        ->and($user->availableStoreCreditBalance())->toBe(0)
        ->and($user->availableRestrictedCreditBalance())->toBe($grant->remaining_amount);
});

it('does not become unrestricted when a specifically allowed product is deleted', function () {
    $user = User::factory()->create();
    $allowedProduct = Product::factory()->for(Costume::factory(), 'productable')->create();
    $otherProduct = Product::factory()->for(Costume::factory(), 'productable')->create();
    $grant = app(CreditLedgerService::class)->issue(
        recipient: $user,
        amount: 2500,
        description: 'Costume credit',
        productIds: [$allowedProduct->id],
    );

    $allowedProduct->delete();
    $grant->unsetRelation('products')->refresh();

    expect($grant->hasRestrictions())->toBeTrue()
        ->and($grant->appliesToProduct($otherProduct))->toBeFalse()
        ->and($user->availableStoreCreditBalance())->toBe(0)
        ->and($user->availableRestrictedCreditBalance())->toBe(2500);
});

it('expires after the selected eastern date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 23:59:59', 'America/New_York'));
    $grant = CreditGrant::factory()->create(['expires_on' => '2026-06-22']);

    expect($grant->status())->toBe(CreditGrantStatus::Active);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 00:00:00', 'America/New_York'));

    expect($grant->status())->toBe(CreditGrantStatus::Expired)
        ->and($grant->availableAmount())->toBe(0)
        ->and($grant->expiredUnusedAmount())->toBe($grant->remaining_amount);
});

it('revokes only the unused remainder with an audit event', function () {
    $issuer = User::factory()->create();
    $grant = CreditGrant::factory()->amount(3500)->create();

    app(CreditLedgerService::class)->revoke($grant, $issuer, 'Issued to the wrong account');

    $grant->refresh();

    expect($grant->status())->toBe(CreditGrantStatus::Revoked)
        ->and($grant->remaining_amount)->toBe(3500)
        ->and($grant->revocation_reason)->toBe('Issued to the wrong account')
        ->and($grant->transactions()->where('type', CreditTransactionType::Revocation)->value('amount'))->toBe(-3500);
});

it('uses the earliest expiring unrestricted credit first', function () {
    $user = User::factory()->create();
    $laterGrant = CreditGrant::factory()->for($user)->amount(3000)->create([
        'expires_on' => now('America/New_York')->addMonth()->toDateString(),
    ]);
    $earlierGrant = CreditGrant::factory()->for($user)->amount(3000)->create([
        'expires_on' => now('America/New_York')->addWeek()->toDateString(),
    ]);
    $order = Order::factory()->for($user)->create(['total' => 4000]);

    $applied = app(CreditLedgerService::class)->applyUnrestrictedToOrder($order, 4000);

    expect($applied)->toBe(4000)
        ->and($earlierGrant->refresh()->remaining_amount)->toBe(0)
        ->and($laterGrant->refresh()->remaining_amount)->toBe(2000);
});
