<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Store\CompleteOrder;
use App\Actions\Store\CreatePaymentPlan;
use App\Actions\Store\SendInstallmentPaymentEmail;
use App\Actions\Store\SendOrderReceipt;
use App\Actions\Store\SendProductPurchaseNotification;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Models\Installment;
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
        private readonly SendOrderReceipt $sendOrderReceipt,
        private readonly SendProductPurchaseNotification $sendProductPurchaseNotification,
        private readonly SendInstallmentPaymentEmail $sendInstallmentPaymentEmail,
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
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
            default => response()->json(['message' => 'Unhandled event type']),
        };
    }

    private function handlePaymentIntentFailed(\Stripe\Event $event): JsonResponse
    {
        $paymentIntent = $event->data->object;
        $installmentId = $paymentIntent->metadata->installment_id ?? null;

        if ($installmentId !== null) {
            return $this->handleInstallmentPaymentFailed($paymentIntent, (string) $installmentId);
        }

        $order = Order::query()
            ->where('stripe_payment_intent_id', $paymentIntent->id)
            ->first();

        if ($order !== null && $order->status === OrderStatus::Processing) {
            $order->update(['status' => OrderStatus::Failed]);

            Log::info("Order #{$order->id} marked as failed due to payment intent failure.", [
                'payment_intent_id' => $paymentIntent->id,
            ]);
        }

        return response()->json(['message' => 'Payment failure handled']);
    }

    private function handlePaymentIntentSucceeded(\Stripe\Event $event): JsonResponse
    {
        $paymentIntent = $event->data->object;

        // Installment PaymentIntents also carry order metadata. Handle the
        // more specific installment lifecycle before checkout completion.
        $installmentId = $paymentIntent->metadata->installment_id ?? null;

        if ($installmentId !== null) {
            return $this->handleInstallmentPaymentSucceeded($paymentIntent, (string) $installmentId);
        }

        // Handle order completion (checkout via Stripe Elements)
        $orderId = $paymentIntent->metadata->order_id ?? null;

        if ($orderId !== null) {
            return $this->handleOrderPaymentSucceeded($paymentIntent, $orderId);
        }

        return response()->json(['message' => 'No order or installment metadata, skipping']);
    }

    private function handleOrderPaymentSucceeded(object $paymentIntent, string $orderId): JsonResponse
    {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            Log::warning("Order #{$orderId} not found for payment_intent.succeeded.", [
                'payment_intent_id' => $paymentIntent->id,
            ]);

            return response()->json(['error' => 'Order not found'], 404);
        }

        if ($order->stripe_payment_intent_id !== $paymentIntent->id) {
            Log::warning("PaymentIntent {$paymentIntent->id} does not match order #{$order->id}.", [
                'expected_payment_intent_id' => $order->stripe_payment_intent_id,
            ]);

            return response()->json(['message' => 'Payment intent does not match order']);
        }

        if (! in_array($order->status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
            return response()->json(['message' => 'Order cannot be processed']);
        }

        if ($order->status === OrderStatus::Processing) {
            $this->completeOrder->handle($order);
        }

        $stripePaymentMethodId = $paymentIntent->payment_method ?? null;
        $stripeCustomerId = $paymentIntent->customer ?? null;

        $order->loadMissing(['paymentPlan', 'paymentPlanTemplate']);

        if ($order->paymentPlanTemplate !== null) {
            if ($order->paymentPlan === null) {
                $createPaymentPlan = new CreatePaymentPlan;
                $createPaymentPlan->handle(
                    order: $order,
                    template: $order->paymentPlanTemplate,
                    stripeCustomerId: is_string($stripeCustomerId) ? $stripeCustomerId : null,
                    stripePaymentMethodId: is_string($stripePaymentMethodId) ? $stripePaymentMethodId : null,
                );

                Log::info("Payment plan created for order #{$order->id}.", [
                    'template_id' => $order->payment_plan_template_id,
                ]);
            } else {
                $paymentPlanUpdates = [];

                if ($order->paymentPlan->stripe_customer_id === null && is_string($stripeCustomerId)) {
                    $paymentPlanUpdates['stripe_customer_id'] = $stripeCustomerId;
                }

                if ($order->paymentPlan->stripe_payment_method_id === null && is_string($stripePaymentMethodId)) {
                    $paymentPlanUpdates['stripe_payment_method_id'] = $stripePaymentMethodId;
                }

                if ($paymentPlanUpdates !== []) {
                    $order->paymentPlan->update($paymentPlanUpdates);
                }
            }
        }

        $this->sendOrderReceipt->handle($order);
        $this->sendProductPurchaseNotification->handle($order);

        Log::info("Order #{$order->id} completed via payment_intent.succeeded.", [
            'payment_intent_id' => $paymentIntent->id,
        ]);

        return response()->json(['message' => 'Order processed']);
    }

    private function handleInstallmentPaymentSucceeded(object $paymentIntent, string $installmentId): JsonResponse
    {
        $installment = Installment::query()->find($installmentId);

        if ($installment === null) {
            Log::warning("Installment #{$installmentId} not found for payment_intent.succeeded.", [
                'payment_intent_id' => $paymentIntent->id,
            ]);

            return response()->json(['error' => 'Installment not found'], 404);
        }

        if ($installment->status !== InstallmentStatus::Paid) {
            $installment->markPaid(stripePaymentIntentId: $paymentIntent->id);
            $this->sendInstallmentPaymentEmail->handle(
                installment: $installment,
                successful: true,
                stripeStatus: 'succeeded',
                stripePaymentIntentId: $this->stringValue($paymentIntent->id ?? null),
                stripeCustomerId: $this->stringValue($paymentIntent->customer ?? null),
                stripePaymentMethodId: $this->stringValue($paymentIntent->payment_method ?? null),
            );
            Log::info("Installment #{$installmentId} marked as paid via webhook.", [
                'payment_intent_id' => $paymentIntent->id,
            ]);
        }

        return response()->json(['message' => 'Installment payment processed']);
    }

    private function handleInstallmentPaymentFailed(object $paymentIntent, string $installmentId): JsonResponse
    {
        $installment = Installment::query()->find($installmentId);

        if ($installment === null) {
            Log::warning("Installment #{$installmentId} not found for payment_intent.payment_failed.", [
                'payment_intent_id' => $paymentIntent->id ?? null,
            ]);

            return response()->json(['error' => 'Installment not found'], 404);
        }

        if (! in_array($installment->status, [
            InstallmentStatus::Paid,
            InstallmentStatus::Failed,
            InstallmentStatus::Overdue,
        ], true)) {
            $installment->markFailed();
            $lastPaymentError = $paymentIntent->last_payment_error ?? null;
            $failureReason = $this->stringValue($lastPaymentError->message ?? null)
                ?? 'We could not process this payment. Please review the payment method on your account.';
            $failureCode = $this->stringValue($lastPaymentError->decline_code ?? null)
                ?? $this->stringValue($lastPaymentError->code ?? null);

            $this->sendInstallmentPaymentEmail->handle(
                installment: $installment,
                successful: false,
                stripeStatus: 'failed',
                stripePaymentIntentId: $this->stringValue($paymentIntent->id ?? null),
                stripeCustomerId: $this->stringValue($paymentIntent->customer ?? null),
                stripePaymentMethodId: $this->stringValue($paymentIntent->payment_method ?? null),
                failureReason: $failureReason,
                failureCode: $failureCode,
            );

            Log::info("Installment #{$installmentId} marked as failed via webhook.", [
                'payment_intent_id' => $paymentIntent->id ?? null,
            ]);
        }

        return response()->json(['message' => 'Installment payment failure processed']);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
