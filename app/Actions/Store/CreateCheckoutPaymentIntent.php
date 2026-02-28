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

final readonly class CreateCheckoutPaymentIntent
{
    public function __construct(
        private StripeServiceContract $stripeService,
    ) {}

    /**
     * Create an order and a Stripe PaymentIntent for custom checkout.
     *
     * @return array{zero_total: bool, order_id: int, client_secret: ?string, checkout_amount: int, cart_summary: array<int, array{name: string, quantity: int, unit_price: int, total_price: int}>, subtotal: int, total: int, discount_display: ?string, credit_display: ?string, payment_plan_display: ?string}
     */
    public function handle(
        User $user,
        ?DiscountCode $discountCode = null,
        int $creditToApply = 0,
        ?PaymentPlanTemplate $paymentPlanTemplate = null,
        ?PaymentPlanMethod $paymentPlanMethod = null,
    ): array {
        return DB::transaction(function () use ($user, $discountCode, $creditToApply, $paymentPlanTemplate, $paymentPlanMethod): array {
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
            $cartSummary = [];

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

                $cartSummary[] = [
                    'name' => $product->name,
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
            ]);

            foreach ($orderItems as $item) {
                $order->orderItems()->create($item);
            }

            $total = $subtotal;
            $discountDisplay = null;
            $creditDisplay = null;

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
                $discountDisplay = "{$discountCode->code} ({$discountCode->formattedValue()} off)";
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

                    $creditDisplay = '$'.number_format($actualCredit / 100, 2).' applied';
                }
            }

            // Payment plan display
            $paymentPlanDisplay = null;
            $usePaymentPlan = $paymentPlanTemplate !== null && $paymentPlanMethod !== null;

            if ($usePaymentPlan) {
                $paymentPlanDisplay = "{$paymentPlanTemplate->name} ({$paymentPlanTemplate->number_of_installments} installments)";
            }

            // If fully covered by discount + credit, complete immediately
            if ($total === 0) {
                $this->completeZeroTotalOrder($order, $user);

                return [
                    'zero_total' => true,
                    'order_id' => $order->id,
                    'client_secret' => null,
                    'checkout_amount' => 0,
                    'cart_summary' => $cartSummary,
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'discount_display' => $discountDisplay,
                    'credit_display' => $creditDisplay,
                    'payment_plan_display' => $paymentPlanDisplay,
                ];
            }

            // Determine the checkout amount (first installment if payment plan, otherwise full total)
            $checkoutAmount = $total;

            if ($usePaymentPlan) {
                $amounts = $paymentPlanTemplate->installmentAmounts($total);
                $checkoutAmount = $amounts['first'];
            }

            // Build metadata
            $metadata = [
                'order_id' => (string) $order->id,
            ];

            if ($usePaymentPlan) {
                $metadata['payment_plan_template_id'] = (string) $paymentPlanTemplate->id;
                $metadata['payment_plan_method'] = $paymentPlanMethod->value;
            }

            // Create Stripe PaymentIntent
            $paymentIntent = $this->stripeService->createPaymentIntent(
                user: $user,
                amount: $checkoutAmount,
                metadata: $metadata,
                setupFutureUsage: $usePaymentPlan,
            );

            $order->update([
                'stripe_payment_intent_id' => $paymentIntent->id,
            ]);

            return [
                'zero_total' => false,
                'order_id' => $order->id,
                'client_secret' => $paymentIntent->client_secret,
                'checkout_amount' => $checkoutAmount,
                'cart_summary' => $cartSummary,
                'subtotal' => $subtotal,
                'total' => $total,
                'discount_display' => $discountDisplay,
                'credit_display' => $creditDisplay,
                'payment_plan_display' => $paymentPlanDisplay,
            ];
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
