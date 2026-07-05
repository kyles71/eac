<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphOne;

interface Productable
{
    public function product(): MorphOne;

    /**
     * Fulfill this product for the given order item.
     *
     * Return true if the item was auto-fulfilled, false if it requires manual fulfillment.
     */
    public function fulfillOrderItem(OrderItem $orderItem, User $purchaser): bool;
}
