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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function paymentPlanTemplate(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanTemplate::class);
    }

    public function paymentPlanTermsVersion(): BelongsTo
    {
        return $this->belongsTo(LegalDocumentVersion::class, 'payment_plan_terms_version_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

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
     * Clear matching cart items for the user, scoped to products on this order.
     *
     * If the cart item quantity is less than or equal to the ordered quantity, it is deleted.
     * If the cart item has more quantity than was ordered, it is decremented.
     */
    public function clearPurchasedCartItems(): void
    {
        $this->loadMissing('orderItems', 'user');

        /** @var User $user */
        $user = $this->user;

        /** @var OrderItem $orderItem */
        foreach ($this->orderItems as $orderItem) {
            /** @var CartItem|null $cartItem */
            $cartItem = $user->cartItems()
                ->where('product_id', $orderItem->product_id)
                ->first();

            if ($cartItem === null) {
                continue;
            }

            if ($cartItem->quantity <= $orderItem->quantity) {
                $cartItem->delete();
            } else {
                $cartItem->update(['quantity' => $cartItem->quantity - $orderItem->quantity]);
            }
        }
    }
}
