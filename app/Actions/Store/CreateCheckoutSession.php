<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\CreditTransactionType;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\Course;
use App\Models\DiscountCode;
use App\Models\GiftCardType;
use App\Models\Order;
use App\Models\PaymentPlanTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class CreateCheckoutSession
{
    public function __construct(
        private StripeServiceContract $stripeService,
    ) {}

    public function handle(
        User $user,
        string $successUrl,
        string $cancelUrl,
        ?DiscountCode $discountCode = null,
        int $creditToApply = 0,
        ?PaymentPlanTemplate $paymentPlanTemplate = null,
        ?PaymentPlanMethod $paymentPlanMethod = null,
    ): string {
        return DB::transaction(function () use ($user, $successUrl, $cancelUrl, $discountCode, $creditToApply, $paymentPlanTemplate, $paymentPlanMethod): string {
            $cartItems = $user->cartItems()->with('product.productable')->get();

            if ($cartItems->isEmpty()) {
                throw new InvalidArgumentException('Your cart is empty.');
            }

            // Soft capacity pre-check
            /** @var \App\Models\CartItem $cartItem */
            foreach ($cartItems as $cartItem) {
                /** @var \App\Models\Product $product */
                $product = $cartItem->product;
                if ($product->productable instanceof Course) {
                    $available = $product->productable->availableCapacity();
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
            $lineItems = [];

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

                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $product->name,
                        ],
                        'unit_amount' => $unitPrice,
                    ],
                    'quantity' => $cartItem->quantity,
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
                $this->completeZeroTotalOrder($order, $user);

                return $successUrl.'?order_id='.$order->id;
            }

            // Determine the checkout amount (first installment if payment plan, otherwise full total)
            $checkoutAmount = $total;
            $usePaymentPlan = $paymentPlanTemplate !== null && $paymentPlanMethod !== null;

            if ($usePaymentPlan) {
                $amounts = $paymentPlanTemplate->installmentAmounts($total);
                $checkoutAmount = $amounts['first'];
            }

            // Build consolidated line item for Stripe
            if ($checkoutAmount < $subtotal || $usePaymentPlan) {
                $label = $usePaymentPlan
                    ? "Order #{$order->id} – Installment 1 of {$paymentPlanTemplate->number_of_installments}"
                    : "Order #{$order->id}";

                $lineItems = [[
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $label,
                        ],
                        'unit_amount' => $checkoutAmount,
                    ],
                    'quantity' => 1,
                ]];
            }

            // Build metadata
            $metadata = [
                'order_id' => (string) $order->id,
            ];

            if ($usePaymentPlan) {
                $metadata['payment_plan_template_id'] = (string) $paymentPlanTemplate->id;
                $metadata['payment_plan_method'] = $paymentPlanMethod->value;
            }

            // Create Stripe Checkout Session
            $session = $this->stripeService->createCheckoutSession(
                user: $user,
                lineItems: $lineItems,
                successUrl: $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
                cancelUrl: $cancelUrl,
                metadata: $metadata,
                setupFutureUsage: $usePaymentPlan,
            );

            $order->update([
                'stripe_checkout_session_id' => $session->id,
            ]);

            return $session->url;
        });
    }

    /**
     * Complete a zero-total order immediately (no Stripe needed).
     */
    private function completeZeroTotalOrder(Order $order, User $user): void
    {
        $order->update(['status' => OrderStatus::Completed]);

        $order->loadMissing('orderItems.product.productable');

        /** @var \App\Models\OrderItem $orderItem */
        foreach ($order->orderItems as $orderItem) {
            /** @var \App\Models\Product $product */
            $product = $orderItem->product;

            if ($product->productable instanceof Course) {
                for ($i = 0; $i < $orderItem->quantity; $i++) {
                    \App\Models\Enrollment::query()->create([
                        'course_id' => $product->productable->id,
                        'user_id' => $user->id,
                        'student_id' => null,
                    ]);
                }
                $orderItem->update(['status' => OrderItemStatus::Fulfilled]);
            } elseif ($product->productable instanceof GiftCardType) {
                $fulfillGiftCard = new FulfillGiftCard;
                $fulfillGiftCard->handle($orderItem, $user);
                $orderItem->update(['status' => OrderItemStatus::Fulfilled]);
            }
            // Costume and standalone products remain Pending for manual fulfillment
        }

        $user->cartItems()->delete();
    }
}
