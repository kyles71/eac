<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'purchase_notification_requested' => 'boolean',
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

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasMany<ProductQuestionAnswer, $this> */
    public function questionAnswers(): HasMany
    {
        return $this->hasMany(ProductQuestionAnswer::class)
            ->orderBy('unit_number')
            ->orderBy('question_order');
    }

    /**
     * Get the formatted unit price in dollars.
     */
    public function formattedUnitPrice(): string
    {
        return format_money($this->unit_price);
    }

    /**
     * Get the formatted total price in dollars.
     */
    public function formattedTotalPrice(): string
    {
        return format_money($this->total_price);
    }

    /**
     * Mark this order item as fulfilled.
     */
    public function markFulfilled(): void
    {
        $this->update(['status' => OrderItemStatus::Fulfilled]);
    }
}
