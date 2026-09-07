<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\CourseHolds\ClaimCourseHoldSeatsForOrder;
use App\Contracts\HasCapacity;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\CourseHoldSeat;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\User;
use App\Services\CreditLedgerService;
use App\Services\ProductAvailabilityService;
use App\Services\ProductQuestionAnswerService;
use App\Support\LegalDocuments\PaymentPlanTerms;
use App\Support\PaymentPlans\PaymentPlanBreakdownCalculator;
use App\Support\Store\AllocateOrderItemPayments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateOrder
{
    public function __construct(
        private readonly CompleteOrder $completeOrder,
        private readonly CancelOrder $cancelOrder,
        private readonly SendGiftCardDeliveryEmails $sendGiftCardDeliveryEmails,
        private readonly SendOrderReceipt $sendOrderReceipt,
        private readonly SendProductPurchaseNotification $sendProductPurchaseNotification,
        private readonly CreditLedgerService $creditLedger,
        private readonly PaymentPlanBreakdownCalculator $paymentPlanBreakdownCalculator,
        private readonly ProductQuestionAnswerService $productQuestionAnswers,
        private readonly ClaimCourseHoldSeatsForOrder $claimCourseHoldSeats,
        private readonly AllocateOrderItemPayments $allocateOrderItemPayments,
    ) {}

    public function handle(
        User $user,
        ?DiscountCode $discountCode = null,
        int $creditToApply = 0,
        ?PaymentPlanTemplate $paymentPlanTemplate = null,
    ): Order {
        return DB::transaction(function () use ($user, $discountCode, $creditToApply, $paymentPlanTemplate): Order {
            if ($paymentPlanTemplate !== null && ! $paymentPlanTemplate->is_active) {
                throw new InvalidArgumentException('The selected payment plan is no longer available.');
            }

            // Cancel any existing pending orders for this user to prevent duplicates
            $pendingOrders = $user->orders()->where('status', OrderStatus::Pending)->get();

            /** @var Order $pendingOrder */
            foreach ($pendingOrders as $pendingOrder) {
                $this->cancelOrder->handle($pendingOrder);
            }

            $cartItems = $user->cartItems()->with(['product.productable', 'product.questions'])->get();

            if ($cartItems->isEmpty()) {
                throw new InvalidArgumentException('Your cart is empty.');
            }

            if ($paymentPlanTemplate !== null
                && $cartItems->contains(fn (CartItem $cartItem): bool => ! $cartItem->product->allows_payment_plan)) {
                throw new InvalidArgumentException('Payment plans are not available for recurring private lessons.');
            }

            $paymentPlanTermsVersion = $paymentPlanTemplate !== null
                ? PaymentPlanTerms::currentVersion()
                : null;

            if ($paymentPlanTemplate !== null && $paymentPlanTermsVersion === null) {
                throw new InvalidArgumentException('Payment plan terms are not available.');
            }

            // Soft capacity pre-check
            /** @var CartItem $cartItem */
            foreach ($cartItems as $cartItem) {
                /** @var Product $product */
                $product = $cartItem->product;

                $availability = app(ProductAvailabilityService::class)->resultFor($product, $user);

                if (! $availability->isPurchasable()) {
                    throw new InvalidArgumentException($availability->message($product->name));
                }

                if ($product->productable instanceof HasCapacity) {
                    if ($cartItem->course_hold_id !== null && $product->productable instanceof Course) {
                        $available = CourseHoldSeat::query()
                            ->where('course_hold_id', $cartItem->course_hold_id)
                            ->where('course_id', $product->productable->id)
                            ->where('locked_unit_price', $cartItem->held_unit_price)
                            ->whereHas('hold', fn (Builder $query): Builder => $query
                                ->where('user_id', $user->id)
                                ->where('expires_at', '>', now()))
                            ->claimable()
                            ->count();
                    } else {
                        $available = $product->productable->getAvailableCapacity();
                    }

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

            /** @var CartItem $cartItem */
            foreach ($cartItems as $cartItem) {
                /** @var Product $product */
                $product = $cartItem->product;
                $unitPrice = $cartItem->effectiveUnitPrice();
                $totalPrice = $cartItem->lineTotal();
                $subtotal += $totalPrice;

                $orderItems[] = [
                    'attributes' => [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'course_hold_id' => $cartItem->course_hold_id,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                        'custom_gift_card_amount' => $cartItem->custom_gift_card_amount,
                        'fulfillment_workflow' => $product->fulfillmentWorkflow(),
                        'purchase_notification_requested' => $product->send_purchase_notification,
                    ],
                    'question_answers' => $this->productQuestionAnswers->orderRows(
                        $cartItem,
                        $cartItem->storedQuestionAnswers(),
                    ),
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
                'payment_plan_fee' => 0,
                'payment_plan_principal' => 0,
                'payment_plan_subtotal' => 0,
                'payment_plan_discount_amount' => 0,
                'payment_plan_restricted_credit_applied' => 0,
                'payment_plan_credit_applied' => 0,
                'payment_plan_template_id' => $paymentPlanTemplate?->id,
                'payment_plan_terms_version_id' => $paymentPlanTermsVersion?->id,
            ]);

            foreach ($orderItems as $item) {
                $orderItem = $order->orderItems()->create($item['attributes']);
                $orderItem->questionAnswers()->createMany($item['question_answers']);
            }

            $this->claimCourseHoldSeats->handle($order);

            $total = $subtotal;
            $discountAmount = 0;
            $actualCredit = 0;
            $restrictedCreditByOrderItemId = [];

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

            $order->load('orderItems.product.productable');

            if ($paymentPlanTemplate !== null) {
                $orderItemsForCreditApplication = $this->paymentPlanBreakdownCalculator->itemsForCreditApplication(
                    $order->orderItems,
                    $paymentPlanTemplate,
                );
                $lineAmountsAfterDiscount = $this->paymentPlanBreakdownCalculator->lineAmountsAfterDiscount(
                    $order->orderItems,
                    $paymentPlanTemplate,
                    $discountAmount,
                );

                $restrictedCreditApplication = $this->creditLedger->applyRestrictedToOrderUsingLineAmounts(
                    $order,
                    $orderItemsForCreditApplication
                        ->mapWithKeys(fn (OrderItem $orderItem): array => [
                            $orderItem->id => $lineAmountsAfterDiscount[$orderItem->id] ?? 0,
                        ])
                        ->all(),
                    max(0, array_sum($lineAmountsAfterDiscount)),
                    $orderItemsForCreditApplication,
                );

                $restrictedCreditTotal = $restrictedCreditApplication['total'];
                $restrictedCreditByOrderItemId = $restrictedCreditApplication['by_key'];
            } else {
                $discountByOrderItemId = $this->allocateOrderItemPayments->allocateProportionally(
                    $order->orderItems->mapWithKeys(
                        fn (OrderItem $orderItem): array => [$orderItem->id => $orderItem->total_price],
                    )->all(),
                    $discountAmount,
                );
                $restrictedCreditApplication = $this->creditLedger->applyRestrictedToOrderUsingLineAmounts(
                    $order,
                    $order->orderItems->mapWithKeys(
                        fn (OrderItem $orderItem): array => [
                            $orderItem->id => max(
                                0,
                                $orderItem->total_price - ($discountByOrderItemId[$orderItem->id] ?? 0),
                            ),
                        ],
                    )->all(),
                    $total,
                );
                $restrictedCreditTotal = $restrictedCreditApplication['total'];
                $restrictedCreditByOrderItemId = $restrictedCreditApplication['by_key'];
            }

            if ($restrictedCreditTotal > 0) {
                $total = max(0, $total - $restrictedCreditTotal);

                $order->update([
                    'restricted_credit_applied' => $restrictedCreditTotal,
                    'total' => $total,
                ]);
            }

            // Apply store credit if requested
            if ($creditToApply > 0 && $total > 0) {
                $actualCredit = $this->creditLedger->applyUnrestrictedToOrder($order, $creditToApply);

                if ($actualCredit > 0) {
                    $total = max(0, $total - $actualCredit);

                    $order->update([
                        'credit_applied' => $actualCredit,
                        'total' => $total,
                    ]);
                }
            }

            if ($paymentPlanTemplate !== null) {
                $paymentPlanBreakdown = $this->paymentPlanBreakdownCalculator->calculate(
                    items: $order->orderItems,
                    template: $paymentPlanTemplate,
                    discountAmount: $discountAmount,
                    restrictedCreditByItemKey: $restrictedCreditByOrderItemId,
                    creditAmount: $actualCredit,
                );

                if (! $paymentPlanBreakdown->hasPrincipal()) {
                    throw new InvalidArgumentException('The selected payment plan is no longer available for this cart.');
                }

                $total += $paymentPlanBreakdown->fee;

                $order->update([
                    'payment_plan_principal' => $paymentPlanBreakdown->principal,
                    'payment_plan_subtotal' => $paymentPlanBreakdown->paymentPlanItemsAmount,
                    'payment_plan_discount_amount' => $paymentPlanBreakdown->paymentPlanDiscountAmount,
                    'payment_plan_restricted_credit_applied' => $paymentPlanBreakdown->paymentPlanRestrictedCreditAmount,
                    'payment_plan_credit_applied' => $paymentPlanBreakdown->paymentPlanCreditAmount,
                    'payment_plan_fee' => $paymentPlanBreakdown->fee,
                    'total' => $total,
                ]);
            }

            if ($paymentPlanTermsVersion !== null) {
                $order->legalDocumentAcceptance()->create([
                    'legal_document_version_id' => $paymentPlanTermsVersion->id,
                    'user_id' => $user->id,
                    'accepted_at' => now(),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            }

            $this->allocateOrderItemPayments->handle($order->refresh(), $restrictedCreditByOrderItemId);

            // If fully covered by discount + credit, complete immediately
            if ($total === 0) {
                if ($this->completeOrder->handle($order)) {
                    $this->sendOrderReceipt->handle($order);
                    $this->sendGiftCardDeliveryEmails->handle($order);
                    $this->sendProductPurchaseNotification->handle($order);
                }
            }

            return $order;
        });
    }
}
