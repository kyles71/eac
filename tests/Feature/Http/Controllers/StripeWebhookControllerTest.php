<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanFrequency;
use App\Enums\PaymentPlanMethod;
use App\Http\Controllers\StripeWebhookController;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
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

it('creates a payment plan when checkout session has template metadata', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_test_123']);

    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
        'frequency' => PaymentPlanFrequency::Monthly,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 9000,
        'total' => 9000,
        'stripe_checkout_session_id' => 'cs_test_plan',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit_price' => 9000,
        'total_price' => 9000,
    ]);

    $event = new Stripe\Event;
    $event->type = 'checkout.session.completed';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'cs_test_plan',
            'payment_intent' => 'pi_test_plan',
            'customer' => 'cus_test_123',
            'metadata' => (object) [
                'order_id' => (string) $order->id,
                'payment_plan_template_id' => (string) $template->id,
                'payment_plan_method' => PaymentPlanMethod::AutoCharge->value,
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
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);

    // Verify payment plan was created
    $paymentPlan = PaymentPlan::query()->where('order_id', $order->id)->first();
    expect($paymentPlan)->not->toBeNull()
        ->and($paymentPlan->method)->toBe(PaymentPlanMethod::AutoCharge)
        ->and($paymentPlan->total_amount)->toBe(9000)
        ->and($paymentPlan->number_of_installments)->toBe(3)
        ->and($paymentPlan->stripe_customer_id)->toBe('cus_test_123');

    // Verify installments were created
    expect($paymentPlan->installments)->toHaveCount(3);
});

it('handles payment_intent.succeeded webhook for installment', function () {
    $plan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_inst_success',
            'metadata' => (object) [
                'installment_id' => (string) $installment->id,
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
    expect($installment->refresh()->status)->toBe(InstallmentStatus::Paid);
    expect($installment->stripe_payment_intent_id)->toBe('pi_test_inst_success');
});

it('handles payment_intent.succeeded without installment metadata gracefully', function () {
    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_no_meta',
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

    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true))->toBe(['message' => 'No installment metadata, skipping']);
});

it('handles invoice.paid webhook for installment', function () {
    $plan = PaymentPlan::factory()->manualInvoice()->create();
    $installment = Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $event = new Stripe\Event;
    $event->type = 'invoice.paid';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'inv_test_paid',
            'metadata' => (object) [
                'installment_id' => (string) $installment->id,
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
    expect($installment->refresh()->status)->toBe(InstallmentStatus::Paid);
    expect($installment->stripe_invoice_id)->toBe('inv_test_paid');
});

it('handles invoice.payment_failed webhook for installment', function () {
    $plan = PaymentPlan::factory()->manualInvoice()->create();
    $installment = Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
        'retry_count' => 0,
    ]);

    $event = new Stripe\Event;
    $event->type = 'invoice.payment_failed';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'inv_test_failed',
            'metadata' => (object) [
                'installment_id' => (string) $installment->id,
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
    expect($installment->refresh()->status)->toBe(InstallmentStatus::Failed);
    expect($installment->retry_count)->toBe(1);
});
