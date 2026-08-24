<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Enums\OrderRefundPaymentStatus;
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
        'payment_plan_principal' => 'integer',
        'payment_plan_subtotal' => 'integer',
        'payment_plan_discount_amount' => 'integer',
        'payment_plan_restricted_credit_applied' => 'integer',
        'payment_plan_credit_applied' => 'integer',
        'payment_plan_template_id' => 'integer',
        'payment_plan_terms_version_id' => 'integer',
        'hold_checkout_expires_at' => 'datetime',
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

    /** @return HasMany<OrderRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class)->latest();
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

    public function paymentPlanInstallmentTotal(): int
    {
        if ($this->paymentPlanTemplate === null) {
            return 0;
        }

        if ($this->payment_plan_principal <= 0) {
            return $this->total;
        }

        return $this->payment_plan_principal + $this->payment_plan_fee;
    }

    public function payInFullAmount(): int
    {
        if ($this->paymentPlanTemplate === null) {
            return $this->total;
        }

        return max(0, $this->total - $this->paymentPlanInstallmentTotal());
    }

    public function paymentPlanItemsSubtotal(): int
    {
        if ($this->paymentPlanTemplate === null) {
            return 0;
        }

        if ($this->payment_plan_subtotal > 0) {
            return $this->payment_plan_subtotal;
        }

        return max(
            0,
            $this->payment_plan_principal
                + $this->payment_plan_discount_amount
                + $this->payment_plan_restricted_credit_applied
                + $this->payment_plan_credit_applied,
        );
    }

    public function payInFullItemsSubtotal(): int
    {
        if ($this->paymentPlanTemplate === null) {
            return $this->subtotal;
        }

        return max(0, $this->subtotal - $this->paymentPlanItemsSubtotal());
    }

    public function payInFullDiscountAmount(): int
    {
        return max(0, $this->discount_amount - $this->payment_plan_discount_amount);
    }

    public function payInFullRestrictedCreditAmount(): int
    {
        return max(0, $this->restricted_credit_applied - $this->payment_plan_restricted_credit_applied);
    }

    public function payInFullCreditAmount(): int
    {
        return max(0, $this->credit_applied - $this->payment_plan_credit_applied);
    }

    public function amountPaidAtCheckout(): int
    {
        if ($this->paymentPlanTemplate === null) {
            return $this->total;
        }

        $amounts = $this->paymentPlanTemplate->installmentAmounts($this->paymentPlanInstallmentTotal());

        return $this->payInFullAmount() + $amounts['first'];
    }

    public function capturedStripeAmount(): int
    {
        $checkoutAmount = $this->stripe_payment_intent_id === null
            ? 0
            : $this->amountPaidAtCheckout();

        if ($this->paymentPlan === null) {
            return $checkoutAmount;
        }

        $installmentAmount = (int) $this->paymentPlan->installments()
            ->where('status', InstallmentStatus::Paid)
            ->whereNotNull('stripe_payment_intent_id')
            ->when(
                $this->stripe_payment_intent_id !== null,
                fn ($query) => $query->where('stripe_payment_intent_id', '!=', $this->stripe_payment_intent_id),
            )
            ->sum('amount');

        return $checkoutAmount + $installmentAmount;
    }

    public function successfulRefundAmount(): int
    {
        return (int) OrderRefundPayment::query()
            ->whereHas('orderRefund', fn ($query) => $query->where('order_id', $this->id))
            ->where('status', OrderRefundPaymentStatus::Succeeded)
            ->sum('amount');
    }

    public function reservedRefundAmount(): int
    {
        return (int) OrderRefundPayment::query()
            ->whereHas('orderRefund', fn ($query) => $query->where('order_id', $this->id))
            ->whereIn('status', [
                OrderRefundPaymentStatus::Processing,
                OrderRefundPaymentStatus::Pending,
                OrderRefundPaymentStatus::RequiresAction,
                OrderRefundPaymentStatus::Succeeded,
            ])
            ->sum('amount');
    }

    public function refundableAmount(): int
    {
        return max(0, $this->capturedStripeAmount() - $this->reservedRefundAmount());
    }

    public function formattedRefundableAmount(): string
    {
        return format_money($this->refundableAmount());
    }

    public function hasChargeableInstallments(): bool
    {
        return $this->paymentPlan?->installments()
            ->whereIn('status', [
                InstallmentStatus::Pending,
                InstallmentStatus::Failed,
                InstallmentStatus::Overdue,
            ])
            ->exists() ?? false;
    }

    /**
     * @return list<array{payment_intent_id: string, amount: int}>
     */
    public function refundablePaymentSources(): array
    {
        $reservedByPaymentIntent = OrderRefundPayment::query()
            ->whereHas('orderRefund', fn ($query) => $query->where('order_id', $this->id))
            ->whereIn('status', [
                OrderRefundPaymentStatus::Processing,
                OrderRefundPaymentStatus::Pending,
                OrderRefundPaymentStatus::RequiresAction,
                OrderRefundPaymentStatus::Succeeded,
            ])
            ->selectRaw('stripe_payment_intent_id, SUM(amount) as reserved_amount')
            ->groupBy('stripe_payment_intent_id')
            ->pluck('reserved_amount', 'stripe_payment_intent_id');

        $sources = [];

        if ($this->paymentPlan !== null) {
            $installments = $this->paymentPlan->installments()
                ->where('status', InstallmentStatus::Paid)
                ->whereNotNull('stripe_payment_intent_id')
                ->when(
                    $this->stripe_payment_intent_id !== null,
                    fn ($query) => $query->where('stripe_payment_intent_id', '!=', $this->stripe_payment_intent_id),
                )
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->get();

            /** @var Installment $installment */
            foreach ($installments as $installment) {
                $paymentIntentId = $installment->stripe_payment_intent_id;

                if (! is_string($paymentIntentId)) {
                    continue;
                }

                $amount = max(0, $installment->amount - (int) ($reservedByPaymentIntent[$paymentIntentId] ?? 0));

                if ($amount > 0) {
                    $sources[] = ['payment_intent_id' => $paymentIntentId, 'amount' => $amount];
                }
            }
        }

        if ($this->stripe_payment_intent_id !== null) {
            $amount = max(
                0,
                $this->amountPaidAtCheckout() - (int) ($reservedByPaymentIntent[$this->stripe_payment_intent_id] ?? 0),
            );

            if ($amount > 0) {
                $sources[] = ['payment_intent_id' => $this->stripe_payment_intent_id, 'amount' => $amount];
            }
        }

        return $sources;
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
                    ->where('course_hold_id', $orderItem->course_hold_id)
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
