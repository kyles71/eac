<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'order_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'total_price' => 'integer',
        'status' => OrderItemStatus::class,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the formatted unit price in dollars.
     */
    public function formattedUnitPrice(): string
    {
        return '$'.number_format($this->unit_price / 100, 2);
    }

    /**
     * Get the formatted total price in dollars.
     */
    public function formattedTotalPrice(): string
    {
        return '$'.number_format($this->total_price / 100, 2);
    }

    /**
     * Mark this order item as fulfilled.
     */
    public function markFulfilled(): void
    {
        $this->update(['status' => OrderItemStatus::Fulfilled]);
    }
}
