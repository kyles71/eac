<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Store\CompleteOrder;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;

final class StripeWebhookController
{
    public function __construct(
        private readonly StripeServiceContract $stripeService,
        private readonly CompleteOrder $completeOrder,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $signature);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        return match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
            default => response()->json(['message' => 'Unhandled event type']),
        };
    }

    private function handleCheckoutSessionCompleted(\Stripe\Event $event): JsonResponse
    {
        $session = $event->data->object;
        $orderId = $session->metadata->order_id ?? null;

        if ($orderId === null) {
            Log::warning('Stripe checkout.session.completed missing order_id metadata.', [
                'session_id' => $session->id,
            ]);

            return response()->json(['error' => 'Missing order_id'], 400);
        }

        $order = Order::query()->find($orderId);

        if ($order === null) {
            Log::warning("Order #{$orderId} not found for checkout session.", [
                'session_id' => $session->id,
            ]);

            return response()->json(['error' => 'Order not found'], 404);
        }

        $order->update([
            'stripe_payment_intent_id' => $session->payment_intent, // @phpstan-ignore property.notFound
        ]);

        $this->completeOrder->handle($order);

        return response()->json(['message' => 'Order processed']);
    }

    private function handlePaymentIntentFailed(\Stripe\Event $event): JsonResponse
    {
        $paymentIntent = $event->data->object;

        $order = Order::query()
            ->where('stripe_payment_intent_id', $paymentIntent->id)
            ->first();

        if ($order !== null && $order->status === OrderStatus::Pending) {
            $order->update(['status' => OrderStatus::Failed]);

            Log::info("Order #{$order->id} marked as failed due to payment intent failure.", [
                'payment_intent_id' => $paymentIntent->id,
            ]);
        }

        return response()->json(['message' => 'Payment failure handled']);
    }
}
