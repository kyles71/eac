<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\User\Pages\CheckoutSuccess;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
});

it('shows completed orders as confirmed', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_completed',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Completed')
        ->assertDontSee('Payment Finalizing');
});

it('reassures users while a successful stripe redirect is finalizing', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Processing,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_processing',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    Livewire::withQueryParams([
        'order_id' => $order->id,
        'redirect_status' => 'succeeded',
    ])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Payment Finalizing')
        ->assertSee('Your payment was submitted successfully')
        ->assertSee('Processing');
});

it('reloads the order status while waiting for webhook completion', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Processing,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_polling',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    $component = Livewire::withQueryParams([
        'order_id' => $order->id,
        'redirect_status' => 'succeeded',
    ])
        ->test(CheckoutSuccess::class)
        ->assertSee('Payment Finalizing');

    $order->update(['status' => OrderStatus::Completed]);

    $component
        ->call('refreshOrderStatus')
        ->assertSee('Completed')
        ->assertDontSee('Payment Finalizing');
});
