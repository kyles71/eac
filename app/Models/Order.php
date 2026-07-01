<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;

final class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status' => OrderStatus::class,
        'subtotal' => 'integer',
        'total' => 'integer',
        'discount_code_id' => 'integer',
        'discount_amount' => 'integer',
        'credit_applied' => 'integer',
        'restricted_credit_applied' => 'integer',
        'payment_plan_fee' => 'integer',
        'payment_plan_template_id' => 'integer',
        'payment_plan_terms_version_id' => 'integer',
        'cart_items_cleared_at' => 'datetime',
        'receipt_queued_at' => 'datetime',
        'purchase_notification_queued_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DiscountCode, $this> */
    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    /** @return BelongsTo<PaymentPlanTemplate, $this> */
    public function paymentPlanTemplate(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanTemplate::class);
    }

    /** @return BelongsTo<LegalDocumentVersion, $this> */
    public function paymentPlanTermsVersion(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'payment_plan_terms_version_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasOne<PaymentPlan, $this> */
    public function paymentPlan(): HasOne
    {
        return $this->hasOne(PaymentPlan::class);
    }

    public function legalDocumentAcceptance(): MorphOne
    {
        return $this->morphOne(LegalDocumentAcceptance::class, 'acceptable');
    }

    /**
     * Get the formatted subtotal in dollars.
     */
    public function formattedSubtotal(): string
    {
        return format_money($this->subtotal);
    }

    /**
     * Get the formatted total in dollars.
     */
    public function formattedTotal(): string
    {
        return format_money($this->total);
    }

    public function formattedPaymentPlanFee(): string
    {
        return format_money($this->payment_plan_fee);
    }

    public function amountPaidAtCheckout(): int
    {
        if ($this->paymentPlanTemplate === null) {
            return $this->total;
        }

        $amounts = $this->paymentPlanTemplate->installmentAmounts($this->total);

        return $amounts['first'];
    }

    /**
     * Clear matching cart items for the user once, scoped to products on this order.
     *
     * If the cart item quantity is less than or equal to the ordered quantity, it is deleted.
     * If the cart item has more quantity than was ordered, it is decremented.
     */
    public function clearPurchasedCartItems(): void
    {
        DB::transaction(function (): void {
            /** @var self|null $order */
            $order = self::query()
                ->lockForUpdate()
                ->find($this->getKey());

            if ($order === null || $order->cart_items_cleared_at !== null) {
                return;
            }

            $order->loadMissing('orderItems');

            /** @var OrderItem $orderItem */
            foreach ($order->orderItems as $orderItem) {
                /** @var CartItem|null $cartItem */
                $cartItem = CartItem::query()
                    ->where('user_id', $order->user_id)
                    ->where('product_id', $orderItem->product_id)
                    ->where('custom_gift_card_amount', $orderItem->custom_gift_card_amount)
                    ->lockForUpdate()
                    ->first();

                if ($cartItem === null) {
                    continue;
                }

                if ($cartItem->quantity <= $orderItem->quantity) {
                    $cartItem->delete();
                } else {
                    $cartItem->update([
                        'quantity' => $cartItem->quantity - $orderItem->quantity,
                        'reminder_sent_at' => null,
                    ]);
                }
            }

            $order->update(['cart_items_cleared_at' => now()]);
        });
    }
}
