<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanFrequency;
use App\Http\Controllers\StripeWebhookController;
use App\Models\CartItem;
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

it('handles payment_intent.succeeded webhook for order completion', function () {
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Processing,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_test_webhook',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_webhook',
            'payment_method' => 'pm_test_123',
            'customer' => 'cus_test_123',
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

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->cart_items_cleared_at)->not->toBeNull()
        ->and(CartItem::query()->where('user_id', $user->id)->count())->toBe(0);
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
        'status' => OrderStatus::Processing,
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

it('handles payment_intent.succeeded without order or installment metadata gracefully', function () {
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
    expect($response->getData(true))->toBe(['message' => 'No order or installment metadata, skipping']);
});

it('does not process an order from a different payment intent with matching metadata', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Processing,
        'stripe_payment_intent_id' => 'pi_expected',
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_wrong_account_or_environment',
            'payment_method' => 'pm_wrong',
            'customer' => 'cus_wrong',
            'metadata' => (object) [
                'order_id' => (string) $order->id,
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')->once()->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $response = app(StripeWebhookController::class)($request);

    expect($response->getData(true))->toBe(['message' => 'Payment intent does not match order'])
        ->and($order->refresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->paymentPlan()->exists())->toBeFalse();
});

it('creates a payment plan when order has payment plan template', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_test_123']);

    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
        'frequency' => PaymentPlanFrequency::Monthly,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Processing,
        'subtotal' => 9000,
        'total' => 9000,
        'stripe_payment_intent_id' => 'pi_test_plan',
        'payment_plan_template_id' => $template->id,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit_price' => 9000,
        'total_price' => 9000,
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_plan',
            'payment_method' => 'pm_test_plan',
            'customer' => 'cus_test_123',
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
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);

    // Verify payment plan was created
    $paymentPlan = PaymentPlan::query()->where('order_id', $order->id)->first();
    expect($paymentPlan)->not->toBeNull()
        ->and($paymentPlan->total_amount)->toBe(9000)
        ->and($paymentPlan->number_of_installments)->toBe(3)
        ->and($paymentPlan->stripe_customer_id)->toBe('cus_test_123')
        ->and($paymentPlan->stripe_payment_method_id)->toBe('pm_test_plan');

    // Verify installments were created
    expect($paymentPlan->installments)->toHaveCount(3);
});

it('idempotently creates a missing plan for an already completed order', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_test_123']);
    $template = PaymentPlanTemplate::factory()->create();
    $order = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'payment_plan_template_id' => $template->id,
        'stripe_payment_intent_id' => 'pi_test_plan_retry',
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_plan_retry',
            'payment_method' => 'pm_test_plan',
            'customer' => 'cus_test_123',
            'metadata' => (object) [
                'order_id' => (string) $order->id,
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')->once()->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $response = app(StripeWebhookController::class)($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($order->paymentPlan()->exists())->toBeTrue()
        ->and($order->paymentPlan()->first()->installments)->toHaveCount($template->number_of_installments)
        ->and($order->paymentPlan()->first()->stripe_payment_method_id)->toBe('pm_test_plan');
});

it('idempotently repairs missing stripe ids without overwriting a selected plan card', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_test_123']);
    $template = PaymentPlanTemplate::factory()->create();

    $missingMethodOrder = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'payment_plan_template_id' => $template->id,
        'stripe_payment_intent_id' => 'pi_test_plan_retry_missing',
    ]);
    $missingMethodPlan = PaymentPlan::factory()->create([
        'order_id' => $missingMethodOrder->id,
        'stripe_customer_id' => null,
        'stripe_payment_method_id' => null,
    ]);

    $selectedMethodOrder = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'payment_plan_template_id' => $template->id,
        'stripe_payment_intent_id' => 'pi_test_plan_retry_selected',
    ]);
    $selectedMethodPlan = PaymentPlan::factory()->create([
        'order_id' => $selectedMethodOrder->id,
        'stripe_payment_method_id' => 'pm_user_selected',
    ]);

    foreach ([
        [$missingMethodOrder, 'pi_test_plan_retry_missing'],
        [$selectedMethodOrder, 'pi_test_plan_retry_selected'],
    ] as [$order, $paymentIntentId]) {
        $event = new Stripe\Event;
        $event->type = 'payment_intent.succeeded';
        $event->data = (object) [
            'object' => (object) [
                'id' => $paymentIntentId,
                'payment_method' => 'pm_checkout',
                'customer' => 'cus_test_123',
                'metadata' => (object) [
                    'order_id' => (string) $order->id,
                ],
            ],
        ];

        $mockStripeService = Mockery::mock(StripeServiceContract::class);
        $mockStripeService->shouldReceive('constructWebhookEvent')->once()->andReturn($event);
        $this->app->instance(StripeServiceContract::class, $mockStripeService);

        $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'test_signature',
        ]);

        expect(app(StripeWebhookController::class)($request)->getStatusCode())->toBe(200);
    }

    expect($missingMethodPlan->refresh()->stripe_customer_id)->toBe('cus_test_123')
        ->and($missingMethodPlan->stripe_payment_method_id)->toBe('pm_checkout')
        ->and($selectedMethodPlan->refresh()->stripe_payment_method_id)->toBe('pm_user_selected');
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

it('ignores invoice webhooks for payment plans', function (string $eventType) {
    $event = new Stripe\Event;
    $event->type = $eventType;
    $event->data = (object) [
        'object' => (object) [
            'id' => 'inv_test',
            'metadata' => (object) [
                'installment_id' => '123',
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
    expect(json_decode((string) $response->getContent(), true))->toBe([
        'message' => 'Unhandled event type',
    ]);
})->with([
    'paid invoice' => 'invoice.paid',
    'failed invoice' => 'invoice.payment_failed',
]);
