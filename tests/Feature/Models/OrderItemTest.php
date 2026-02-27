<?php

declare(strict_types=1);

use App\Enums\OrderItemStatus;
use App\Models\OrderItem;

it('can mark an order item as fulfilled', function () {
    $orderItem = OrderItem::factory()->create();

    expect($orderItem->status)->toBe(OrderItemStatus::Pending);

    $orderItem->markFulfilled();

    expect($orderItem->refresh()->status)->toBe(OrderItemStatus::Fulfilled);
});

it('defaults to pending status', function () {
    $orderItem = OrderItem::factory()->create();

    expect($orderItem->status)->toBe(OrderItemStatus::Pending);
});
