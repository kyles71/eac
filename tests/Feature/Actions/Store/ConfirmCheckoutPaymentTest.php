<?php

declare(strict_types=1);

use App\Actions\Store\ConfirmCheckoutPayment;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\User;
use Stripe\PaymentIntent;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('completes the order when payment intent has succeeded', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_test_confirm',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_confirm',
        'status' => 'succeeded',
        'customer' => 'cus_test_123',
        'payment_method' => 'pm_test_123',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('retrievePaymentIntent')
        ->with('pi_test_confirm')
        ->once()
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(ConfirmCheckoutPayment::class);
    $action->handle($order);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Completed);

    expect(Enrollment::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('throws when order is not pending', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Completed,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_test_completed',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(ConfirmCheckoutPayment::class);
    $action->handle($order);
})->throws(InvalidArgumentException::class, 'Order is not in a pending state.');

it('throws when payment intent has not succeeded', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_test_incomplete',
    ]);

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_incomplete',
        'status' => 'requires_payment_method',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('retrievePaymentIntent')
        ->with('pi_test_incomplete')
        ->once()
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(ConfirmCheckoutPayment::class);
    $action->handle($order);
})->throws(InvalidArgumentException::class, 'Payment has not been completed successfully.');

it('throws when order has no payment intent', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => null,
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(ConfirmCheckoutPayment::class);
    $action->handle($order);
})->throws(InvalidArgumentException::class, 'Order has no associated payment intent.');

it('creates a payment plan when template is provided', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 10000,
        'total' => 10000,
        'stripe_payment_intent_id' => 'pi_test_plan',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit_price' => 5000,
        'total_price' => 10000,
    ]);

    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
        'min_price' => 1000,
        'max_price' => 50000,
    ]);

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_plan',
        'status' => 'succeeded',
        'customer' => 'cus_test_456',
        'payment_method' => 'pm_test_456',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('retrievePaymentIntent')
        ->with('pi_test_plan')
        ->once()
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(ConfirmCheckoutPayment::class);
    $action->handle($order, $template, PaymentPlanMethod::AutoCharge);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Completed);

    // Verify user payment method was saved
    expect($this->user->refresh()->stripe_payment_method_id)->toBe('pm_test_456');

    // Verify payment plan was created
    expect($order->paymentPlan)->not->toBeNull();
});
