<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Filament\User\Pages\Cart;
use App\Filament\User\Pages\Checkout;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');

    // Refresh auth user to load all DB columns (stripe_id, etc.) for ShouldBeStrict compatibility.
    auth()->user()->refresh();

    $paymentIntent = Stripe\PaymentIntent::constructFrom([
        'id' => 'pi_test_123',
        'client_secret' => 'pi_test_123_secret',
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createPaymentIntent')->andReturn($paymentIntent);
    $stripeMock->shouldReceive('createCustomerSession')->andReturnNull();

    $this->app->instance(StripeServiceContract::class, $stripeMock);
});

it('loads the pending order without a query param', function () {
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
    ]);

    OrderItem::factory()->create(['order_id' => $order->id]);

    livewire(Checkout::class)
        ->assertOk()
        ->assertSet('order.id', $order->id);
});

it('redirects to cart when no pending order exists', function () {
    livewire(Checkout::class)
        ->assertRedirect(Cart::getUrl());
});

it('does not load another users pending order', function () {
    $otherUser = User::factory()->create();

    Order::factory()->create([
        'user_id' => $otherUser->id,
        'status' => OrderStatus::Pending,
    ]);

    livewire(Checkout::class)
        ->assertRedirect(Cart::getUrl());
});

it('does not load a completed order', function () {
    Order::factory()->completed()->create([
        'user_id' => auth()->id(),
    ]);

    livewire(Checkout::class)
        ->assertRedirect(Cart::getUrl());
});

it('marks the order as processing', function () {
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
    ]);

    OrderItem::factory()->create(['order_id' => $order->id]);

    livewire(Checkout::class)
        ->assertOk()
        ->call('markOrderProcessing');

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);
});

it('reverts a processing order back to pending', function () {
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
    ]);

    OrderItem::factory()->create(['order_id' => $order->id]);

    livewire(Checkout::class)
        ->assertOk()
        ->call('markOrderProcessing')
        ->call('revertOrderToPending');

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('does not clear the cart when marking order as processing', function () {
    $product = Product::factory()->create(['price' => 5000]);

    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 5000,
        'total_price' => 10000,
    ]);

    /** @var User $user */
    $user = auth()->user();

    CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    expect($user->cartItems()->count())->toBe(1);

    livewire(Checkout::class)
        ->assertOk()
        ->call('markOrderProcessing');

    expect($order->refresh()->status)->toBe(OrderStatus::Processing);

    // Cart should NOT be cleared yet — payment hasn't been confirmed
    expect($user->cartItems()->count())->toBe(1);
});
