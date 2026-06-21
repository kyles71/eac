<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\HasCapacity;
use App\Enums\CreditTransactionType;
use App\Enums\OrderStatus;
use App\Enums\ProductAvailabilityStatus;
use App\Enums\ProductQuestionType;
use App\Models\CartItem;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\User;
use App\Services\ProductAvailabilityService;
use App\Support\LegalDocuments\PaymentPlanTerms;
use App\Support\PaymentPlanFee;
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
    ) {}

    public function handle(
        User $user,
        ?DiscountCode $discountCode = null,
        int $creditToApply = 0,
        ?PaymentPlanTemplate $paymentPlanTemplate = null,
        array $questionAnswers = [],
    ): Order {
        return DB::transaction(function () use ($user, $discountCode, $creditToApply, $paymentPlanTemplate, $questionAnswers): Order {
            $paymentPlanTermsVersion = $paymentPlanTemplate !== null
                ? PaymentPlanTerms::currentVersion()
                : null;

            if ($paymentPlanTemplate !== null && $paymentPlanTermsVersion === null) {
                throw new InvalidArgumentException('Payment plan terms are not available.');
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

            // Soft capacity pre-check
            /** @var CartItem $cartItem */
            foreach ($cartItems as $cartItem) {
                /** @var Product $product */
                $product = $cartItem->product;

                $availability = app(ProductAvailabilityService::class)->resultFor($product, $user);

                if (! $availability->isPurchasable()) {
                    throw new InvalidArgumentException($this->cartItemUnavailableMessage($product, $availability));
                }

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
            $answerRowsByProductId = [];

            /** @var CartItem $cartItem */
            foreach ($cartItems as $cartItem) {
                /** @var Product $product */
                $product = $cartItem->product;
                $unitPrice = $product->price;
                $totalPrice = $unitPrice * $cartItem->quantity;
                $subtotal += $totalPrice;

                $answerRowsByProductId[$product->id] = $this->normalizeQuestionAnswers(
                    $cartItem,
                    $questionAnswers[$cartItem->id] ?? [],
                );

                $orderItems[] = [
                    'product_id' => $product->id,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'purchase_notification_requested' => $product->send_purchase_notification,
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
                'payment_plan_template_id' => $paymentPlanTemplate?->id,
                'payment_plan_terms_version_id' => $paymentPlanTermsVersion?->id,
            ]);

            foreach ($orderItems as $item) {
                $orderItem = $order->orderItems()->create($item);
                $orderItem->questionAnswers()->createMany(
                    $answerRowsByProductId[$orderItem->product_id] ?? [],
                );
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

                /** @var Product $product */
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
                    "Limited use credit applied to order #{$order->id}",
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

            if ($paymentPlanTemplate !== null) {
                $paymentPlanFee = PaymentPlanFee::calculate($total);
                $total += $paymentPlanFee;

                $order->update([
                    'payment_plan_fee' => $paymentPlanFee,
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

    private function cartItemUnavailableMessage(Product $product, ProductAvailabilityStatus $availability): string
    {
        return match ($availability) {
            ProductAvailabilityStatus::EnrollmentRequired => "\"{$product->name}\" requires an existing course enrollment.",
            ProductAvailabilityStatus::InvalidPrice => "\"{$product->name}\" does not have a valid price.",
            ProductAvailabilityStatus::Scheduled => "\"{$product->name}\" is not available yet.",
            ProductAvailabilityStatus::Expired => "\"{$product->name}\" is no longer available for purchase.",
            default => "\"{$product->name}\" is not available for purchase.",
        };
    }

    /**
     * @param  array<int|string, mixed>  $submittedUnits
     * @return list<array<string, mixed>>
     */
    private function normalizeQuestionAnswers(CartItem $cartItem, array $submittedUnits): array
    {
        $rows = [];

        for ($unitNumber = 1; $unitNumber <= $cartItem->quantity; $unitNumber++) {
            $submittedAnswers = $submittedUnits[$unitNumber] ?? [];
            $submittedAnswers = is_array($submittedAnswers) ? $submittedAnswers : [];

            /** @var ProductQuestion $question */
            foreach ($cartItem->product->questions as $question) {
                $fieldName = "question_{$question->id}";
                $submittedAnswer = $submittedAnswers[$fieldName] ?? null;
                $selectedOption = null;
                $answer = null;

                if ($question->type === ProductQuestionType::Text) {
                    $answer = $this->normalizeStringAnswer($submittedAnswer);

                    if ($question->is_required && $answer === null) {
                        throw new InvalidArgumentException($this->requiredQuestionMessage($cartItem, $question, $unitNumber));
                    }

                    if ($answer !== null && $question->max_length !== null && mb_strlen($answer) > $question->max_length) {
                        throw new InvalidArgumentException(
                            "Your answer to \"{$question->question}\" may not be longer than {$question->max_length} characters.",
                        );
                    }
                } else {
                    $selectedOption = $this->normalizeStringAnswer($submittedAnswer);

                    if ($question->is_required && $selectedOption === null) {
                        throw new InvalidArgumentException($this->requiredQuestionMessage($cartItem, $question, $unitNumber));
                    }

                    if ($selectedOption === 'Other') {
                        if (! $question->allows_other) {
                            throw new InvalidArgumentException("Other is not a valid answer to \"{$question->question}\".");
                        }

                        $answer = $this->normalizeStringAnswer($submittedAnswers["{$fieldName}_other"] ?? null);

                        if ($question->is_required && $answer === null) {
                            throw new InvalidArgumentException("Please specify the Other answer for \"{$question->question}\".");
                        }

                        if ($answer !== null && mb_strlen($answer) > 255) {
                            throw new InvalidArgumentException("The Other answer to \"{$question->question}\" may not be longer than 255 characters.");
                        }

                        $answer ??= 'Other';
                    } elseif ($selectedOption !== null && ! in_array($selectedOption, $question->options ?? [], true)) {
                        throw new InvalidArgumentException("The selected answer to \"{$question->question}\" is no longer available.");
                    }
                }

                $rows[] = [
                    'product_question_id' => $question->id,
                    'unit_number' => $unitNumber,
                    'question' => $question->question,
                    'question_type' => $question->type,
                    'was_required' => $question->is_required,
                    'question_order' => $question->sort_order,
                    'selected_option' => $selectedOption,
                    'answer' => $answer,
                ];
            }
        }

        return $rows;
    }

    private function normalizeStringAnswer(mixed $answer): ?string
    {
        if ($answer === null) {
            return null;
        }

        if (! is_string($answer)) {
            throw new InvalidArgumentException('A purchaser question answer had an invalid format.');
        }

        $answer = mb_trim($answer);

        return $answer === '' ? null : $answer;
    }

    private function requiredQuestionMessage(CartItem $cartItem, ProductQuestion $question, int $unitNumber): string
    {
        $item = $cartItem->quantity === 1
            ? $cartItem->product->name
            : "{$cartItem->product->name} item {$unitNumber}";

        return "Please answer \"{$question->question}\" for {$item}.";
    }
}
