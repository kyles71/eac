<?php

declare(strict_types=1);

use App\Actions\Store\CompleteOrder;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('creates enrollments and marks order as completed', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 10000,
        'total' => 10000,
        'stripe_payment_intent_id' => 'pi_test_123',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit_price' => 5000,
        'total_price' => 10000,
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CompleteOrder::class);
    $result = $action->handle($order);

    expect($result)->toBeTrue();
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);

    // Should have created 2 enrollments
    $enrollments = Enrollment::query()
        ->where('course_id', $this->course->id)
        ->where('user_id', $this->user->id)
        ->get();

    expect($enrollments)->toHaveCount(2);
    expect($enrollments->every(fn ($e) => $e->student_id === null))->toBeTrue();
});

it('clears the users cart after completion', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    // Add a cart item
    $this->user->cartItems()->create([
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    expect($this->user->cartItems()->count())->toBe(1);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CompleteOrder::class);
    $action->handle($order);

    expect($this->user->cartItems()->count())->toBe(0);
});

it('fails and refunds when capacity is exceeded at completion time', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 15000,
        'total' => 15000,
        'stripe_payment_intent_id' => 'pi_test_overcapacity',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
        'unit_price' => 5000,
        'total_price' => 15000,
    ]);

    // Fill 4 of 5 spots (only 1 remaining, but order wants 3)
    for ($i = 0; $i < 4; $i++) {
        Enrollment::factory()->create(['course_id' => $this->course->id]);
    }

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('refundPaymentIntent')
        ->once()
        ->with('pi_test_overcapacity');

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CompleteOrder::class);
    $result = $action->handle($order);

    expect($result)->toBeFalse();
    expect($order->refresh()->status)->toBe(OrderStatus::Failed);

    // No enrollments should have been created
    $enrollments = Enrollment::query()
        ->where('course_id', $this->course->id)
        ->where('user_id', $this->user->id)
        ->count();

    expect($enrollments)->toBe(0);
});

it('skips if order is not pending', function () {
    $order = Order::factory()->completed()->create([
        'user_id' => $this->user->id,
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CompleteOrder::class);
    $result = $action->handle($order);

    expect($result)->toBeFalse();
});

it('handles multiple order items for different courses', function () {
    $course2 = Course::factory()->create(['capacity' => 10]);
    $product2 = Product::factory()->forCourse($course2)->create(['price' => 7500]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 20000,
        'total' => 20000,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
        'unit_price' => 5000,
        'total_price' => 10000,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 1,
        'unit_price' => 7500,
        'total_price' => 7500,
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CompleteOrder::class);
    $result = $action->handle($order);

    expect($result)->toBeTrue();

    // 2 enrollments for course 1
    expect(Enrollment::query()->where('course_id', $this->course->id)->count())->toBe(2);

    // 1 enrollment for course 2
    expect(Enrollment::query()->where('course_id', $course2->id)->count())->toBe(1);
});
