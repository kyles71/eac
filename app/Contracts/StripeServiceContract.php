<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Customer;
use Stripe\Event;
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
    ): StripeSession;

    public function constructWebhookEvent(string $payload, string $signature): Event;

    public function refundPaymentIntent(string $paymentIntentId, ?int $amount = null): Refund;
}
