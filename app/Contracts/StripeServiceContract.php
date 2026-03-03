<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\PaymentIntent;
use Stripe\Refund;

interface StripeServiceContract
{
    public function createOrGetCustomer(User $user): Customer;

    /**
     * Create an on-session PaymentIntent for the given user and amount.
     *
     * @param  array<string, string>  $metadata
     */
    public function createPaymentIntent(
        User $user,
        int $amount,
        array $metadata = [],
        bool $setupFutureUsage = false,
    ): PaymentIntent;

    /**
     * Get saved payment methods for a Stripe customer.
     *
     * @return Collection<int, \Stripe\PaymentMethod>
     */
    public function getPaymentMethods(string $customerId): Collection;

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

    /**
     * Confirm a PaymentIntent with a specific payment method (server-side).
     */
    public function confirmPaymentIntent(string $paymentIntentId, string $paymentMethodId): PaymentIntent;
}
