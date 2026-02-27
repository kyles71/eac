<?php

declare(strict_types=1);

use App\Models\DiscountCode;
use App\Models\Product;
use App\Models\User;

it('scopes usable codes correctly', function () {
    DiscountCode::factory()->create(['code' => 'ACTIVE']);
    DiscountCode::factory()->inactive()->create(['code' => 'INACTIVE']);
    DiscountCode::factory()->expired()->create(['code' => 'EXPIRED']);
    DiscountCode::factory()->exhausted()->create(['code' => 'EXHAUSTED']);

    $usable = DiscountCode::query()->usable()->pluck('code')->all();

    expect($usable)->toBe(['ACTIVE']);
});

it('validates for a specific user', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->create();

    expect($code->isValidForUser($user))->toBeTrue();
});

it('rejects inactive codes for a user', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->inactive()->create();

    expect($code->isValidForUser($user))->toBeFalse();
});

it('rejects expired codes for a user', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->expired()->create();

    expect($code->isValidForUser($user))->toBeFalse();
});

it('rejects exhausted codes for a user', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->exhausted()->create();

    expect($code->isValidForUser($user))->toBeFalse();
});

it('respects per-user usage limits', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->perUser(1)->create();

    // Simulate a prior use
    App\Models\Order::factory()->create([
        'user_id' => $user->id,
        'discount_code_id' => $code->id,
    ]);

    expect($code->isValidForUser($user))->toBeFalse();
});

it('calculates percentage discount correctly', function () {
    $code = DiscountCode::factory()->percentage(20)->create();

    expect($code->calculateDiscount(10000))->toBe(2000);
});

it('calculates fixed amount discount correctly', function () {
    $code = DiscountCode::factory()->fixedAmount(1500)->create();

    expect($code->calculateDiscount(10000))->toBe(1500);
});

it('caps discount at subtotal', function () {
    $code = DiscountCode::factory()->fixedAmount(15000)->create();

    expect($code->calculateDiscount(10000))->toBe(10000);
});

it('checks product scope when has restrictions', function () {
    $product1 = Product::factory()->create();
    $product2 = Product::factory()->create();
    $code = DiscountCode::factory()->create();

    $code->products()->attach($product1);

    expect($code->appliesToProduct($product1))->toBeTrue()
        ->and($code->appliesToProduct($product2))->toBeFalse();
});

it('applies to all products when no restrictions', function () {
    $product = Product::factory()->create();
    $code = DiscountCode::factory()->create();

    expect($code->appliesToProduct($product))->toBeTrue();
});

it('formats percentage value correctly', function () {
    $code = DiscountCode::factory()->percentage(25)->create();

    expect($code->formattedValue())->toBe('25%');
});

it('formats fixed amount value correctly', function () {
    $code = DiscountCode::factory()->fixedAmount(1050)->create();

    expect($code->formattedValue())->toBe('$10.50');
});
