<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\PaymentIntent;
use Stripe\Refund;

interface StripeServiceContract
{
    public function createOrGetCustomer(User $user): Customer;

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  array<string, string>  $metadata
     */
    public function createCheckoutSession(
        User $user,
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
        bool $setupFutureUsage = false,
    ): StripeSession;

    public function constructWebhookEvent(string $payload, string $signature): Event;

    public function refundPaymentIntent(string $paymentIntentId, ?int $amount = null): Refund;

    /**
     * Charge a saved payment method off-session.
     *
     * @param  array<string, string>  $metadata
     */
    public function chargePaymentMethod(
        string $customerId,
        string $paymentMethodId,
        int $amount,
        string $description = '',
        array $metadata = [],
    ): PaymentIntent;

    /**
     * Create and send a Stripe invoice to the customer.
     *
     * @param  array<string, string>  $metadata
     */
    public function createAndSendInvoice(
        string $customerId,
        int $amount,
        string $description = '',
        array $metadata = [],
    ): Invoice;
}
