<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StripeServiceContract;
use App\Models\User;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;

final readonly class StripeService implements StripeServiceContract
{
    public function __construct(
        private StripeClient $client,
    ) {}

    public function createOrGetCustomer(User $user): Customer
    {
        if ($user->stripe_id !== null) {
            return $this->client->customers->retrieve($user->stripe_id);
        }

        $customer = $this->client->customers->create([
            'email' => $user->email,
            'name' => $user->full_name, // @phpstan-ignore property.notFound
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        $user->update(['stripe_id' => $customer->id]);

        return $customer;
    }

    /**
     * @param  array<int, array{price_data: array{currency: string, product_data: array{name: string}, unit_amount: int}, quantity: int}>  $lineItems
     * @param  array<string, string>  $metadata
     */
    public function createCheckoutSession(
        User $user,
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): CheckoutSession {
        $customer = $this->createOrGetCustomer($user);

        return $this->client->checkout->sessions->create([
            'customer' => $customer->id,
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @throws SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        return Webhook::constructEvent(
            $payload,
            $signature,
            config('services.stripe.webhook_secret'),
        );
    }

    public function refundPaymentIntent(string $paymentIntentId, ?int $amount = null): Refund
    {
        $params = ['payment_intent' => $paymentIntentId];

        if ($amount !== null) {
            $params['amount'] = $amount;
        }

        return $this->client->refunds->create($params);
    }
}
