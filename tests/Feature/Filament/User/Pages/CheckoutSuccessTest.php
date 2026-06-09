<?php

declare(strict_types=1);

use App\Actions\Store\CreatePaymentPlan;
use App\Enums\OrderStatus;
use App\Filament\User\Pages\CheckoutSuccess;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlanTemplate;
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
        'payment_intent' => 'pi_processing',
        'redirect_status' => 'succeeded',
    ])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Payment Finalizing')
        ->assertSee('Your payment was submitted successfully')
        ->assertSee('Processing');
});

it('clears purchased cart items on a successful stripe return', function () {
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

    CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    Livewire::withQueryParams([
        'order_id' => $order->id,
        'payment_intent' => 'pi_processing',
        'redirect_status' => 'succeeded',
    ])
        ->test(CheckoutSuccess::class)
        ->assertOk();

    expect(CartItem::query()->where('user_id', auth()->id())->count())->toBe(0)
        ->and($order->refresh()->cart_items_cleared_at)->not->toBeNull();
});

it('does not clear cart items without a successful stripe return', function () {
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

    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk();

    expect($cartItem->refresh()->quantity)->toBe(1)
        ->and($order->refresh()->cart_items_cleared_at)->toBeNull();
});

it('does not clear cart items for a pending order', function () {
    $product = Product::factory()->create(['price' => 5000]);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_pending',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    $cartItem = CartItem::factory()->create([
        'user_id' => auth()->id(),
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    Livewire::withQueryParams([
        'order_id' => $order->id,
        'redirect_status' => 'succeeded',
    ])
        ->test(CheckoutSuccess::class)
        ->assertOk();

    expect($cartItem->refresh()->quantity)->toBe(1)
        ->and($order->refresh()->cart_items_cleared_at)->toBeNull();
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

it('shows actual paid amount and payment plan details for payment plan orders', function () {
    $product = Product::factory()->create(['price' => 10000]);
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
    ]);

    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 10000,
        'payment_plan_fee' => 300,
        'total' => 10300,
        'payment_plan_template_id' => $template->id,
        'stripe_payment_intent_id' => 'pi_plan',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
    ]);

    (new CreatePaymentPlan)->handle($order, $template);

    Livewire::withQueryParams(['order_id' => $order->id])
        ->test(CheckoutSuccess::class)
        ->assertOk()
        ->assertSee('Total Paid')
        ->assertSee('$25.75')
        ->assertSee('Payment Plan Details')
        ->assertSee('4 Monthly payments')
        ->assertSee('$3.00')
        ->assertSee('$103.00')
        ->assertSee('$77.25');
});
