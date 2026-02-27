<?php

declare(strict_types=1);

use App\Actions\Store\ApplyDiscountCode;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;

it('returns a valid discount code', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->percentage(20)->create();

    $action = new ApplyDiscountCode;
    $result = $action->handle($code->code, $user, 10000);

    expect($result->id)->toBe($code->id);
});

it('rejects an invalid code', function () {
    $user = User::factory()->create();

    $action = new ApplyDiscountCode;
    $action->handle('DOESNOTEXIST', $user, 10000);
})->throws(InvalidArgumentException::class, 'Invalid discount code.');

it('rejects an inactive code', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->inactive()->create();

    $action = new ApplyDiscountCode;
    $action->handle($code->code, $user, 10000);
})->throws(InvalidArgumentException::class, 'This discount code is no longer valid.');

it('rejects an expired code', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->expired()->create();

    $action = new ApplyDiscountCode;
    $action->handle($code->code, $user, 10000);
})->throws(InvalidArgumentException::class, 'This discount code is no longer valid.');

it('rejects when below minimum order amount', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->minOrderAmount(5000)->create();

    $action = new ApplyDiscountCode;
    $action->handle($code->code, $user, 2000);
})->throws(InvalidArgumentException::class, 'This discount code requires a minimum order of $50.00.');

it('rejects when product scope does not match cart', function () {
    $user = User::factory()->create();
    $scopedProduct = Product::factory()->create();
    $cartProduct = Product::factory()->create();
    $code = DiscountCode::factory()->create();
    $code->products()->attach($scopedProduct);

    $action = new ApplyDiscountCode;
    $action->handle($code->code, $user, 10000, [$cartProduct->id]);
})->throws(InvalidArgumentException::class, 'This discount code does not apply to any items in your cart.');

it('accepts when product scope matches cart', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $code = DiscountCode::factory()->create();
    $code->products()->attach($product);

    $action = new ApplyDiscountCode;
    $result = $action->handle($code->code, $user, 10000, [$product->id]);

    expect($result->id)->toBe($code->id);
});

it('rejects when per-user limit is reached', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->perUser(1)->create();

    Order::factory()->create([
        'user_id' => $user->id,
        'discount_code_id' => $code->id,
    ]);

    $action = new ApplyDiscountCode;
    $action->handle($code->code, $user, 10000);
})->throws(InvalidArgumentException::class, 'This discount code is no longer valid.');

it('rejects when globally exhausted', function () {
    $user = User::factory()->create();
    $code = DiscountCode::factory()->exhausted()->create();

    $action = new ApplyDiscountCode;
    $action->handle($code->code, $user, 10000);
})->throws(InvalidArgumentException::class, 'This discount code is no longer valid.');
