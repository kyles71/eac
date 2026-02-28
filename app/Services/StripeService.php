<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StripeServiceContract;
use App\Models\User;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\PaymentIntent;
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
        bool $setupFutureUsage = false,
    ): CheckoutSession {
        $customer = $this->createOrGetCustomer($user);

        $params = [
            'customer' => $customer->id,
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
        ];

        if ($setupFutureUsage) {
            $params['payment_intent_data'] = [
                'setup_future_usage' => 'off_session',
            ];
        }

        return $this->client->checkout->sessions->create($params);
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

    /**
     * @param  array<string, string>  $metadata
     */
    public function chargePaymentMethod(
        string $customerId,
        string $paymentMethodId,
        int $amount,
        string $description = '',
        array $metadata = [],
    ): PaymentIntent {
        return $this->client->paymentIntents->create([
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            'amount' => $amount,
            'currency' => 'usd',
            'description' => $description,
            'metadata' => $metadata,
            'off_session' => true,
            'confirm' => true,
        ]);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    public function createAndSendInvoice(
        string $customerId,
        int $amount,
        string $description = '',
        array $metadata = [],
    ): Invoice {
        // Create an invoice item
        $this->client->invoiceItems->create([
            'customer' => $customerId,
            'amount' => $amount,
            'currency' => 'usd',
            'description' => $description,
        ]);

        // Create and send the invoice
        $invoice = $this->client->invoices->create([
            'customer' => $customerId,
            'auto_advance' => true,
            'collection_method' => 'send_invoice',
            'days_until_due' => 7,
            'metadata' => $metadata,
        ]);

        return $this->client->invoices->sendInvoice($invoice->id);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    public function createPaymentIntent(
        User $user,
        int $amount,
        array $metadata = [],
        bool $setupFutureUsage = false,
    ): PaymentIntent {
        $customer = $this->createOrGetCustomer($user);

        $params = [
            'customer' => $customer->id,
            'amount' => $amount,
            'currency' => 'usd',
            'metadata' => $metadata,
            'automatic_payment_methods' => ['enabled' => true],
        ];

        if ($setupFutureUsage) {
            $params['setup_future_usage'] = 'off_session';
        }

        return $this->client->paymentIntents->create($params);
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->client->paymentIntents->retrieve($paymentIntentId);
    }
}
