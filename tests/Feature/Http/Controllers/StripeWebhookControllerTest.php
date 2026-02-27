<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Http\Controllers\StripeWebhookController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->product = Product::factory()->create(['price' => 5000]);
});

it('handles checkout session completed webhook', function () {
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_checkout_session_id' => 'cs_test_webhook',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    $event = new Stripe\Event;
    $event->type = 'checkout.session.completed';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'cs_test_webhook',
            'payment_intent' => 'pi_test_webhook',
            'metadata' => (object) [
                'order_id' => (string) $order->id,
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true))->toBe(['message' => 'Order processed']);

    expect($order->refresh()->status)->toBe(OrderStatus::Completed);
    expect($order->stripe_payment_intent_id)->toBe('pi_test_webhook');
});

it('returns 400 for invalid webhook signature', function () {
    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andThrow(new Stripe\Exception\SignatureVerificationException('Invalid signature'));

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'invalid_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(400);
    expect($response->getData(true))->toBe(['error' => 'Invalid signature']);
});

it('handles unrecognized event types gracefully', function () {
    $event = new Stripe\Event;
    $event->type = 'some.unknown.event';
    $event->data = (object) ['object' => (object) []];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true))->toBe(['message' => 'Unhandled event type']);
});

it('handles payment intent failed webhook', function () {
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Pending,
        'stripe_payment_intent_id' => 'pi_test_failed',
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.payment_failed';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_failed',
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect($order->refresh()->status)->toBe(OrderStatus::Failed);
});

it('returns 400 when checkout session is missing order_id metadata', function () {
    $event = new Stripe\Event;
    $event->type = 'checkout.session.completed';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'cs_test_no_meta',
            'payment_intent' => 'pi_test',
            'metadata' => (object) [],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(400);
    expect($response->getData(true))->toBe(['error' => 'Missing order_id']);
});
