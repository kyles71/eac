<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\StripeService;
use Stripe\Customer;
use Stripe\CustomerSession;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use Stripe\StripeClient;

it('creates a new payment intent without preassigning a saved payment method', function () {
    $paymentIntents = new class
    {
        /** @var array<string, mixed> */
        public array $createdWith = [];

        /** @param array<string, mixed> $params */
        public function create(array $params): PaymentIntent
        {
            $this->createdWith = $params;

            return PaymentIntent::constructFrom(['id' => 'pi_test']);
        }
    };

    $customers = stripeCustomerServiceForTest();
    $service = new StripeService(stripeClientForTest([
        'customers' => $customers,
        'paymentIntents' => $paymentIntents,
    ]));

    $user = new User;
    $user->stripe_id = 'cus_test';

    $service->createPaymentIntent(
        user: $user,
        amount: 5000,
        metadata: ['order_id' => '123'],
    );

    expect($paymentIntents->createdWith)->toMatchArray([
        'customer' => 'cus_test',
        'amount' => 5000,
        'currency' => 'usd',
        'metadata' => ['order_id' => '123'],
    ])->not->toHaveKey('payment_method');
});

it('redisplays every attached customer payment method while disabling removal', function () {
    $customerSessions = new class
    {
        /** @var array<string, mixed> */
        public array $createdWith = [];

        /** @param array<string, mixed> $params */
        public function create(array $params): CustomerSession
        {
            $this->createdWith = $params;

            return CustomerSession::constructFrom(['client_secret' => 'cuss_test_secret']);
        }
    };

    $service = new StripeService(stripeClientForTest([
        'customerSessions' => $customerSessions,
    ]));

    $service->createCustomerSession('cus_test', allowPaymentMethodSave: false);

    expect($customerSessions->createdWith)->toMatchArray([
        'customer' => 'cus_test',
        'components' => [
            'payment_element' => [
                'enabled' => true,
                'features' => [
                    'payment_method_redisplay' => 'enabled',
                    'payment_method_allow_redisplay_filters' => ['always', 'limited', 'unspecified'],
                    'payment_method_save' => 'disabled',
                    'payment_method_remove' => 'disabled',
                ],
            ],
        ],
    ]);
});

it('makes an attached payment method redisplayable', function () {
    $paymentMethods = new class
    {
        /** @var array<string, mixed> */
        public array $updatedWith = [];

        /** @param array<string, mixed> $params */
        public function update(string $paymentMethodId, array $params): PaymentMethod
        {
            $this->updatedWith = compact('paymentMethodId', 'params');

            return PaymentMethod::constructFrom(['id' => $paymentMethodId]);
        }
    };

    $service = new StripeService(stripeClientForTest([
        'paymentMethods' => $paymentMethods,
    ]));

    $service->makePaymentMethodRedisplayable('pm_plan');

    expect($paymentMethods->updatedWith)->toBe([
        'paymentMethodId' => 'pm_plan',
        'params' => ['allow_redisplay' => 'always'],
    ]);
});

it('creates an idempotent partial refund with private identifiers only', function (): void {
    $refunds = new class
    {
        /** @var array<string, mixed> */
        public array $createdWith = [];

        /** @var array<string, mixed> */
        public array $requestOptions = [];

        /**
         * @param  array<string, mixed>  $params
         * @param  array<string, mixed>  $options
         */
        public function create(array $params, array $options): Refund
        {
            $this->createdWith = $params;
            $this->requestOptions = $options;

            return Refund::constructFrom(['id' => 're_test', 'status' => 'succeeded']);
        }
    };

    $service = new StripeService(stripeClientForTest(['refunds' => $refunds]));

    $service->refundPaymentIntent(
        paymentIntentId: 'pi_test',
        amount: 2500,
        metadata: [
            'order_id' => '123',
            'order_refund_id' => '456',
        ],
        idempotencyKey: 'order-refund-payment-789',
    );

    expect($refunds->createdWith)->toBe([
        'payment_intent' => 'pi_test',
        'amount' => 2500,
        'metadata' => [
            'order_id' => '123',
            'order_refund_id' => '456',
        ],
    ])->and($refunds->requestOptions)->toBe([
        'stripe_version' => '2026-05-27.dahlia',
        'idempotency_key' => 'order-refund-payment-789',
    ]);
});

it('passes an idempotency key when refunding a payment intent without metadata', function (): void {
    $refunds = new class
    {
        /** @var array<string, mixed> */
        public array $createdWith = [];

        /** @var array<string, mixed> */
        public array $requestOptions = [];

        /**
         * @param  array<string, mixed>  $params
         * @param  array<string, mixed>  $options
         */
        public function create(array $params, array $options): Refund
        {
            $this->createdWith = $params;
            $this->requestOptions = $options;

            return Refund::constructFrom(['id' => 're_private_lesson']);
        }
    };

    $service = new StripeService(stripeClientForTest([
        'refunds' => $refunds,
    ]));

    $service->refundPaymentIntent(
        paymentIntentId: 'pi_private_lesson',
        amount: 6000,
        idempotencyKey: 'recurring-private-lesson-coverage-123-refund-idempotency-key',
    );

    expect($refunds->createdWith)->toBe([
        'payment_intent' => 'pi_private_lesson',
        'amount' => 6000,
    ])->and($refunds->requestOptions)->toBe([
        'stripe_version' => '2026-05-27.dahlia',
        'idempotency_key' => 'recurring-private-lesson-coverage-123-refund-idempotency-key',
    ]);
});

/**
 * @param  array<string, object>  $services
 */
function stripeClientForTest(array $services): StripeClient
{
    return new class($services) extends StripeClient
    {
        /** @param array<string, object> $services */
        public function __construct(private readonly array $services) {}

        public function getService($name): object
        {
            return $this->services[$name];
        }
    };
}

function stripeCustomerServiceForTest(): object
{
    return new class
    {
        public function retrieve(string $customerId): Customer
        {
            return Customer::constructFrom(['id' => $customerId]);
        }
    };
}
