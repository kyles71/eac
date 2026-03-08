<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('cancelPaymentIntent')->byDefault();
    $this->app->instance(StripeServiceContract::class, $mockStripeService);
});

it('cancels pending orders older than the threshold', function () {
    $oldOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'created_at' => now()->subHours(25),
    ]);

    $this->artisan('orders:cancel-abandoned', ['--hours' => 24])
        ->assertSuccessful();

    expect($oldOrder->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('does not cancel recent pending orders', function () {
    $recentOrder = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'created_at' => now()->subHours(12),
    ]);

    $this->artisan('orders:cancel-abandoned', ['--hours' => 24])
        ->assertSuccessful();

    expect($recentOrder->refresh()->status)->toBe(OrderStatus::Pending);
});

it('does not cancel completed or failed orders', function () {
    $completedOrder = Order::factory()->completed()->create([
        'user_id' => $this->user->id,
        'created_at' => now()->subHours(48),
    ]);

    $failedOrder = Order::factory()->failed()->create([
        'user_id' => $this->user->id,
        'created_at' => now()->subHours(48),
    ]);

    $this->artisan('orders:cancel-abandoned', ['--hours' => 24])
        ->assertSuccessful();

    expect($completedOrder->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($failedOrder->refresh()->status)->toBe(OrderStatus::Failed);
});

it('uses a custom hours threshold', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'created_at' => now()->subHours(3),
    ]);

    $this->artisan('orders:cancel-abandoned', ['--hours' => 2])
        ->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('cancels multiple abandoned orders', function () {
    $orders = collect([
        Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::Pending,
            'created_at' => now()->subHours(30),
        ]),
        Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::Pending,
            'created_at' => now()->subHours(48),
        ]),
    ]);

    $this->artisan('orders:cancel-abandoned', ['--hours' => 24])
        ->assertSuccessful();

    $orders->each(fn (Order $order) => expect($order->refresh()->status)->toBe(OrderStatus::Cancelled));
});
