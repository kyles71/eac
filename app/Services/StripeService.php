<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StripeServiceContract;
use App\Models\User;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use Stripe\SetupIntent;
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
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ];

        if ($setupFutureUsage) {
            $params['setup_future_usage'] = 'off_session';
        }

        return $this->client->paymentIntents->create($params);
    }

    public function createCustomerSession(string $customerId): \Stripe\CustomerSession
    {
        return $this->client->customerSessions->create([
            'customer' => $customerId,
            'components' => [
                'payment_element' => [
                    'enabled' => true,
                    'features' => [
                        'payment_method_redisplay' => 'enabled',
                        'payment_method_save' => 'enabled',
                        'payment_method_save_usage' => 'off_session',
                        'payment_method_remove' => 'enabled',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, string>  $metadata
     */
    public function createSetupIntent(User $user, array $metadata = []): SetupIntent
    {
        $customer = $this->createOrGetCustomer($user);

        return $this->client->setupIntents->create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'usage' => 'off_session',
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return list<PaymentMethod>
     */
    public function listPaymentMethods(string $customerId, string $type = 'card'): array
    {
        return $this->client->paymentMethods->all([
            'customer' => $customerId,
            'type' => $type,
        ])->data;
    }

    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): Customer
    {
        return $this->client->customers->update($customerId, [
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId,
            ],
        ]);
    }

    public function detachPaymentMethod(string $paymentMethodId): PaymentMethod
    {
        return $this->client->paymentMethods->detach($paymentMethodId);
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

    public function cancelPaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->client->paymentIntents->cancel($paymentIntentId);
    }
}
