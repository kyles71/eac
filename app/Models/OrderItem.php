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
        'course_hold_id' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'total_price' => 'integer',
        'discount_allocated' => 'integer',
        'restricted_credit_allocated' => 'integer',
        'credit_allocated' => 'integer',
        'stripe_allocated' => 'integer',
        'custom_gift_card_amount' => 'integer',
        'status' => OrderItemStatus::class,
        'purchase_notification_requested' => 'boolean',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<CourseHold, $this> */
    public function courseHold(): BelongsTo
    {
        return $this->belongsTo(CourseHold::class);
    }

    /** @return HasMany<CourseHoldSeat, $this> */
    public function claimedCourseHoldSeats(): HasMany
    {
        return $this->hasMany(CourseHoldSeat::class, 'claimed_order_item_id');
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

    public function customGiftCardAmount(): ?int
    {
        return $this->custom_gift_card_amount > 0 ? $this->custom_gift_card_amount : null;
    }

    /**
     * Mark this order item as fulfilled.
     */
    public function markFulfilled(): void
    {
        $this->update(['status' => OrderItemStatus::Fulfilled]);
    }

    protected static function booted(): void
    {
        self::creating(function (OrderItem $orderItem): void {
            if (filled($orderItem->product_name) || $orderItem->product_id === null) {
                return;
            }

            $orderItem->product_name = Product::query()
                ->whereKey($orderItem->product_id)
                ->value('name');
        });
    }
}
