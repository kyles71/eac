<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Store\CompleteOrder;
use App\Actions\Store\CreatePaymentPlan;
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
            'invoice.paid' => $this->handleInvoicePaid($event),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event),
            default => response()->json(['message' => 'Unhandled event type']),
        };
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

    private function handlePaymentIntentSucceeded(\Stripe\Event $event): JsonResponse
    {
        $paymentIntent = $event->data->object;

        // Handle order completion (checkout via Stripe Elements)
        $orderId = $paymentIntent->metadata->order_id ?? null;

        if ($orderId !== null) {
            return $this->handleOrderPaymentSucceeded($paymentIntent, $orderId);
        }

        // Handle installment payment
        $installmentId = $paymentIntent->metadata->installment_id ?? null;

        if ($installmentId !== null) {
            return $this->handleInstallmentPaymentSucceeded($paymentIntent, $installmentId);
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

        if ($order->status !== OrderStatus::Pending) {
            return response()->json(['message' => 'Order already processed']);
        }

        $this->completeOrder->handle($order);

        $stripePaymentMethodId = $paymentIntent->payment_method ?? null;

        // Create payment plan if configured on the order
        $order->loadMissing('paymentPlanTemplate');

        if ($order->paymentPlanTemplate !== null && $order->payment_plan_method !== null) {
            $stripeCustomerId = $paymentIntent->customer ?? null;

            $createPaymentPlan = new CreatePaymentPlan;
            $createPaymentPlan->handle(
                order: $order,
                template: $order->paymentPlanTemplate,
                method: $order->payment_plan_method,
                stripeCustomerId: $stripeCustomerId,
                stripePaymentMethodId: $stripePaymentMethodId,
            );

            Log::info("Payment plan created for order #{$order->id}.", [
                'template_id' => $order->payment_plan_template_id,
                'method' => $order->payment_plan_method->value,
            ]);
        }

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
            Log::info("Installment #{$installmentId} marked as paid via webhook.", [
                'payment_intent_id' => $paymentIntent->id,
            ]);
        }

        return response()->json(['message' => 'Installment payment processed']);
    }

    private function handleInvoicePaid(\Stripe\Event $event): JsonResponse
    {
        $invoice = $event->data->object;
        $installmentId = $invoice->metadata->installment_id ?? null;

        if ($installmentId === null) {
            return response()->json(['message' => 'No installment metadata, skipping']);
        }

        $installment = Installment::query()->find($installmentId);

        if ($installment === null) {
            Log::warning("Installment #{$installmentId} not found for invoice.paid.", [
                'invoice_id' => $invoice->id,
            ]);

            return response()->json(['error' => 'Installment not found'], 404);
        }

        if ($installment->status !== InstallmentStatus::Paid) {
            $installment->markPaid(stripeInvoiceId: $invoice->id);
            Log::info("Installment #{$installmentId} marked as paid via invoice.", [
                'invoice_id' => $invoice->id,
            ]);
        }

        return response()->json(['message' => 'Invoice payment processed']);
    }

    private function handleInvoicePaymentFailed(\Stripe\Event $event): JsonResponse
    {
        $invoice = $event->data->object;
        $installmentId = $invoice->metadata->installment_id ?? null;

        if ($installmentId === null) {
            return response()->json(['message' => 'No installment metadata, skipping']);
        }

        $installment = Installment::query()->find($installmentId);

        if ($installment === null) {
            Log::warning("Installment #{$installmentId} not found for invoice.payment_failed.", [
                'invoice_id' => $invoice->id,
            ]);

            return response()->json(['error' => 'Installment not found'], 404);
        }

        $installment->markFailed();
        Log::info("Installment #{$installmentId} marked as failed via invoice payment failure.", [
            'invoice_id' => $invoice->id,
        ]);

        return response()->json(['message' => 'Invoice payment failure handled']);
    }
}
