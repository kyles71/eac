<?php

declare(strict_types=1);

use App\Actions\Store\CreateCheckoutSession;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Stripe\Checkout\Session as StripeSession;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('creates an order and returns a stripe checkout url', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_123',
        'url' => 'https://checkout.stripe.com/test',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn($mockSession);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $url = $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel');

    expect($url)->toBe('https://checkout.stripe.com/test');

    // Verify order was created
    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order)->not->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->subtotal)->toBe(10000) // 2 * 5000
        ->and($order->total)->toBe(10000)
        ->and($order->stripe_checkout_session_id)->toBe('cs_test_123');

    // Verify order items
    $orderItems = OrderItem::query()->where('order_id', $order->id)->get();
    expect($orderItems)->toHaveCount(1)
        ->and($orderItems->first()->product_id)->toBe($this->product->id)
        ->and($orderItems->first()->quantity)->toBe(2)
        ->and($orderItems->first()->unit_price)->toBe(5000)
        ->and($orderItems->first()->total_price)->toBe(10000);
});

it('fails when cart is empty', function () {
    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel');
})->throws(InvalidArgumentException::class, 'Your cart is empty.');

it('fails when course capacity is insufficient at checkout', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
    ]);

    // Fill all 5 spots
    for ($i = 0; $i < 5; $i++) {
        Enrollment::factory()->create(['course_id' => $this->course->id]);
    }

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel');
})->throws(InvalidArgumentException::class);

it('creates an order with multiple cart items', function () {
    $course2 = Course::factory()->create(['capacity' => 10]);
    $product2 = Product::factory()->forCourse($course2)->create(['price' => 7500]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $product2->id,
        'quantity' => 2,
    ]);

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_456',
        'url' => 'https://checkout.stripe.com/test2',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn($mockSession);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $url = $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel');

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->subtotal)->toBe(20000) // 5000 + (7500 * 2)
        ->and($order->orderItems)->toHaveCount(2);
});
