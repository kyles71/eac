<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Store\CompleteOrder;
use App\Actions\Store\CreatePaymentPlan;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\Installment;
use App\Models\Order;
use App\Models\PaymentPlanTemplate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
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
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
            'invoice.paid' => $this->handleInvoicePaid($event),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event),
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

        // Create payment plan if template metadata is present
        $templateId = $session->metadata->payment_plan_template_id ?? null;
        $methodValue = $session->metadata->payment_plan_method ?? null;

        if ($templateId !== null && $methodValue !== null) {
            $template = PaymentPlanTemplate::query()->find($templateId);
            $method = PaymentPlanMethod::from($methodValue);

            if ($template !== null) {
                // Extract customer and payment method from the session
                $stripeCustomerId = $session->customer ?? null;
                $stripePaymentMethodId = null;

                // Try to get the payment method from the payment intent
                if ($session->payment_intent !== null) { // @phpstan-ignore property.notFound
                    try {
                        $stripeService = app(StripeServiceContract::class);
                        /** @var \Stripe\StripeClient $client */
                        $client = (new ReflectionProperty($stripeService, 'client'))->getValue($stripeService);
                        $paymentIntent = $client->paymentIntents->retrieve($session->payment_intent);
                        $stripePaymentMethodId = $paymentIntent->payment_method;
                    } catch (Exception $e) {
                        Log::warning("Could not retrieve payment method from payment intent: {$e->getMessage()}");
                    }
                }

                // Save payment method to user
                if ($stripePaymentMethodId !== null) {
                    /** @var \App\Models\User $user */
                    $user = $order->user;
                    $user->update(['stripe_payment_method_id' => $stripePaymentMethodId]);
                }

                $createPaymentPlan = new CreatePaymentPlan;
                $createPaymentPlan->handle(
                    order: $order,
                    template: $template,
                    method: $method,
                    stripeCustomerId: $stripeCustomerId,
                    stripePaymentMethodId: $stripePaymentMethodId,
                );

                Log::info("Payment plan created for order #{$order->id}.", [
                    'template_id' => $templateId,
                    'method' => $methodValue,
                ]);
            }
        }

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

    private function handlePaymentIntentSucceeded(\Stripe\Event $event): JsonResponse
    {
        $paymentIntent = $event->data->object;
        $installmentId = $paymentIntent->metadata->installment_id ?? null;

        if ($installmentId === null) {
            return response()->json(['message' => 'No installment metadata, skipping']);
        }

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
