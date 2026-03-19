<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\OrderItem;
use App\Models\User;

interface Productable
{
    /**
     * Fulfill this product for the given order item.
     *
     * Return true if the item was auto-fulfilled, false if it requires manual fulfillment.
     */
    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool;
}
