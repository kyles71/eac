<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class CreateCheckoutSession
{
    public function __construct(
        private StripeServiceContract $stripeService,
    ) {}

    public function handle(User $user, string $successUrl, string $cancelUrl): string
    {
        return DB::transaction(function () use ($user, $successUrl, $cancelUrl): string {
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
            ]);

            foreach ($orderItems as $item) {
                $order->orderItems()->create($item);
            }

            // Create Stripe Checkout Session
            $session = $this->stripeService->createCheckoutSession(
                user: $user,
                lineItems: $lineItems,
                successUrl: $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
                cancelUrl: $cancelUrl,
                metadata: [
                    'order_id' => (string) $order->id,
                ],
            );

            $order->update([
                'stripe_checkout_session_id' => $session->id,
            ]);

            return $session->url;
        });
    }
}
