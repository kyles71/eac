<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\HasCapacity;
use App\Enums\CreditTransactionType;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\PaymentPlanTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateOrder
{
    public function __construct(
        private readonly CompleteOrder $completeOrder,
        private readonly CancelOrder $cancelOrder,
    ) {}

    public function handle(
        User $user,
        ?DiscountCode $discountCode = null,
        int $creditToApply = 0,
        ?PaymentPlanTemplate $paymentPlanTemplate = null,
        ?PaymentPlanMethod $paymentPlanMethod = null,
    ): Order {
        return DB::transaction(function () use ($user, $discountCode, $creditToApply, $paymentPlanTemplate, $paymentPlanMethod): Order {
            // Cancel any existing pending orders for this user to prevent duplicates
            $pendingOrders = $user->orders()->where('status', OrderStatus::Pending)->get();

            /** @var Order $pendingOrder */
            foreach ($pendingOrders as $pendingOrder) {
                $this->cancelOrder->handle($pendingOrder);
            }

            $cartItems = $user->cartItems()->with('product.productable')->get();

            if ($cartItems->isEmpty()) {
                throw new InvalidArgumentException('Your cart is empty.');
            }

            // Soft capacity pre-check
            /** @var \App\Models\CartItem $cartItem */
            foreach ($cartItems as $cartItem) {
                /** @var \App\Models\Product $product */
                $product = $cartItem->product;

                if ($product->productable instanceof HasCapacity) {
                    $available = $product->productable->getAvailableCapacity();

                    if ($cartItem->quantity > $available) {
                        throw new InvalidArgumentException(
                            "Not enough spots available for \"{$product->name}\". Only {$available} remaining."
                        );
                    }
                }
            }

            // Calculate totals and create order
            $subtotal = 0;
            $orderItems = [];

            /** @var \App\Models\CartItem $cartItem */
            foreach ($cartItems as $cartItem) {
                /** @var \App\Models\Product $product */
                $product = $cartItem->product;
                $unitPrice = $product->price;
                $totalPrice = $unitPrice * $cartItem->quantity;
                $subtotal += $totalPrice;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                ];
            }

            $order = Order::query()->create([
                'user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'discount_code_id' => null,
                'discount_amount' => 0,
                'credit_applied' => 0,
                'restricted_credit_applied' => 0,
                'payment_plan_template_id' => $paymentPlanTemplate?->id,
                'payment_plan_method' => $paymentPlanMethod?->value,
            ]);

            foreach ($orderItems as $item) {
                $order->orderItems()->create($item);
            }

            $total = $subtotal;

            // Apply discount code if provided
            if ($discountCode !== null) {
                $discountAmount = $discountCode->calculateDiscount($subtotal);
                $total = max(0, $subtotal - $discountAmount);

                $order->update([
                    'discount_code_id' => $discountCode->id,
                    'discount_amount' => $discountAmount,
                    'total' => $total,
                ]);

                $discountCode->increment('times_used');
            }

            // Apply restricted credits to eligible items
            $restrictedCreditTotal = 0;
            $order->loadMissing('orderItems.product.productable');

            /** @var \App\Models\OrderItem $orderItem */
            foreach ($order->orderItems as $orderItem) {
                if ($total <= 0) {
                    break;
                }

                /** @var \App\Models\Product $product */
                $product = $orderItem->product;
                $itemTotal = $orderItem->total_price;

                $availableRestricted = $user->getRestrictedCreditForProduct($product);

                if ($availableRestricted > 0) {
                    $applicableAmount = min($availableRestricted, $itemTotal, $total);
                    $actualDebited = $user->applyRestrictedCredit($product, $applicableAmount);

                    if ($actualDebited > 0) {
                        $restrictedCreditTotal += $actualDebited;
                        $total = max(0, $total - $actualDebited);
                    }
                }
            }

            if ($restrictedCreditTotal > 0) {
                $order->update([
                    'restricted_credit_applied' => $restrictedCreditTotal,
                    'total' => $total,
                ]);

                $user->adjustCredit(
                    0,
                    CreditTransactionType::CheckoutDebit,
                    $order,
                    "Restricted credit applied to order #{$order->id}",
                );
            }

            // Apply store credit if requested
            if ($creditToApply > 0 && $total > 0) {
                $user->refresh();
                $actualCredit = min($creditToApply, $total, $user->credit_balance);

                if ($actualCredit > 0) {
                    $total = max(0, $total - $actualCredit);

                    $order->update([
                        'credit_applied' => $actualCredit,
                        'total' => $total,
                    ]);

                    $user->adjustCredit(
                        -$actualCredit,
                        CreditTransactionType::CheckoutDebit,
                        $order,
                        'Applied to order #'.$order->id,
                    );
                }
            }

            // If fully covered by discount + credit, complete immediately
            if ($total === 0) {
                $this->completeOrder->handle($order);
            }

            return $order;
        });
    }
}
