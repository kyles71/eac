<?php

declare(strict_types=1);

use App\Actions\Store\FulfillGiftCard;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->giftCardType = GiftCardType::factory()->denomination(5000)->create();
    $this->product = Product::factory()->forGiftCardType($this->giftCardType)->create(['price' => 5000]);
    $this->order = Order::factory()->create(['user_id' => $this->user->id]);
});

it('creates a gift card for a purchased gift card type', function () {
    $orderItem = OrderItem::factory()->create([
        'order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    $orderItem->load('product.productable');

    $action = new FulfillGiftCard;
    $giftCards = $action->handle($orderItem, $this->user);

    expect($giftCards)->toHaveCount(1);

    $giftCard = $giftCards[0];
    expect($giftCard->initial_amount)->toBe(5000)
        ->and($giftCard->remaining_amount)->toBe(5000)
        ->and($giftCard->purchased_by_user_id)->toBe($this->user->id)
        ->and($giftCard->order_id)->toBe($this->order->id)
        ->and($giftCard->is_active)->toBeTrue()
        ->and($giftCard->redeemed_at)->toBeNull()
        ->and(mb_strlen($giftCard->code))->toBe(16);
});

it('creates multiple gift cards for quantity > 1', function () {
    $orderItem = OrderItem::factory()->create([
        'order_id' => $this->order->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit_price' => 5000,
        'total_price' => 15000,
    ]);

    $orderItem->load('product.productable');

    $action = new FulfillGiftCard;
    $giftCards = $action->handle($orderItem, $this->user);

    expect($giftCards)->toHaveCount(3);

    // Each should have a unique code
    $codes = array_map(fn (GiftCard $gc) => $gc->code, $giftCards);
    expect(array_unique($codes))->toHaveCount(3);
});

it('uses denomination amount when set on the gift card type', function () {
    $giftCardType = GiftCardType::factory()->denomination(10000)->create();
    $product = Product::factory()->forGiftCardType($giftCardType)->create(['price' => 10000]);

    $orderItem = OrderItem::factory()->create([
        'order_id' => $this->order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
    ]);

    $orderItem->load('product.productable');

    $action = new FulfillGiftCard;
    $giftCards = $action->handle($orderItem, $this->user);

    expect($giftCards[0]->initial_amount)->toBe(10000);
});

it('uses product price when denomination is zero (custom)', function () {
    $giftCardType = GiftCardType::factory()->custom()->create();
    $product = Product::factory()->forGiftCardType($giftCardType)->create(['price' => 7500]);

    $orderItem = OrderItem::factory()->create([
        'order_id' => $this->order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 7500,
        'total_price' => 7500,
    ]);

    $orderItem->load('product.productable');

    $action = new FulfillGiftCard;
    $giftCards = $action->handle($orderItem, $this->user);

    expect($giftCards[0]->initial_amount)->toBe(7500);
});
