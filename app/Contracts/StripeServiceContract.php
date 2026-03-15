<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
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
     * Create a Customer Session for Payment Element saved-card features.
     */
    public function createCustomerSession(string $customerId): \Stripe\CustomerSession;

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
     * Cancel a PaymentIntent that has not yet been captured or confirmed.
     */
    public function cancelPaymentIntent(string $paymentIntentId): PaymentIntent;
}
