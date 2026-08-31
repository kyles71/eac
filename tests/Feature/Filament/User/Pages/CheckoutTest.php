<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Filament\User\Pages\Cart;
use App\Filament\User\Pages\Checkout;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\ProductQuestionAnswer;
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

it("shows each unit's purchaser questions and answers in the checkout cart", function (): void {
    $product = Product::factory()->create(['name' => 'Competition Shirt']);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
    ]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);
    ProductQuestionAnswer::factory()->create([
        'order_item_id' => $orderItem->id,
        'product_question_id' => null,
        'unit_number' => 1,
        'question' => 'Dancer name',
        'answer' => 'Avery',
    ]);
    ProductQuestionAnswer::factory()->create([
        'order_item_id' => $orderItem->id,
        'product_question_id' => null,
        'unit_number' => 2,
        'question' => 'Dancer name',
        'answer' => 'Jordan',
    ]);

    livewire(Checkout::class)
        ->assertOk()
        ->assertSeeInOrder([
            'Item 1 of 2',
            'Dancer name',
            'Avery',
            'Item 2 of 2',
            'Dancer name',
            'Jordan',
        ])
        ->assertSeeHtml('<dt class="font-bold">Dancer name</dt>')
        ->assertDontSee('Dancer name:');
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

it('does not create a payment intent when a pending Order is no longer available to the customer', function () {
    $product = Product::factory()->create(['name' => 'Restricted Jacket']);
    $product->requiredCourses()->attach(Course::factory()->create());
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldNotReceive('createPaymentIntent');
    $stripeMock->shouldNotReceive('createCustomerSession');
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Checkout::class)
        ->assertNotified('Checkout unavailable')
        ->assertRedirect(Cart::getUrl())
        ->assertSet('order', null);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->stripe_payment_intent_id)->toBeNull();
});

it('rechecks Product availability immediately before payment processing', function () {
    $product = Product::factory()->create(['name' => 'Restricted Jacket']);
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
    ]);

    $component = livewire(Checkout::class)
        ->assertOk()
        ->assertSet('order.id', $order->id);

    $product->requiredCourses()->attach(Course::factory()->create());

    $component
        ->call('markOrderProcessing')
        ->assertNotified('Checkout unavailable')
        ->assertRedirect(Cart::getUrl())
        ->assertSet('order', null);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('shows limited use credit in the checkout summary', function () {
    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'restricted_credit_applied' => 2500,
        'total' => 2500,
    ]);

    OrderItem::factory()->create(['order_id' => $order->id]);

    livewire(Checkout::class)
        ->assertOk()
        ->assertSee('Limited Use Credit')
        ->assertSee('-$25.00')
        ->assertDontSee('Restricted Credit');
});

it('charges only the first fee-inclusive installment for payment plan checkout', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
    ]);

    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
        'subtotal' => 10000,
        'payment_plan_fee' => 300,
        'total' => 10300,
        'payment_plan_template_id' => $template->id,
    ]);

    OrderItem::factory()->create(['order_id' => $order->id]);

    $paymentIntent = Stripe\PaymentIntent::constructFrom([
        'id' => 'pi_plan_123',
        'client_secret' => 'pi_plan_123_secret',
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createPaymentIntent')
        ->once()
        ->withArgs(fn (User $user, int $amount, array $metadata, bool $setupFutureUsage): bool => $amount === 2575
            && $metadata['order_id'] === (string) $order->id
            && $setupFutureUsage === true)
        ->andReturn($paymentIntent);
    $stripeMock->shouldReceive('createCustomerSession')
        ->once()
        ->with('cus_test_123', false)
        ->andReturn(Stripe\CustomerSession::constructFrom([
            'client_secret' => 'cuss_test_secret',
        ]));

    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Checkout::class)
        ->assertOk()
        ->assertSet('clientSecret', 'pi_plan_123_secret')
        ->assertSee('the payment method used today will be securely saved in Stripe for future installments and purchases')
        ->assertSee("?order_id={$order->id}';", false)
        ->assertSee("allow_redisplay: 'always'", false);
});

it('charges pay in full items plus the first installment for mixed payment plan checkout', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
    ]);

    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
        'subtotal' => 8000,
        'payment_plan_principal' => 5000,
        'payment_plan_subtotal' => 5000,
        'payment_plan_fee' => 150,
        'total' => 8150,
        'payment_plan_template_id' => $template->id,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'total_price' => 5000,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'total_price' => 3000,
    ]);

    $paymentIntent = Stripe\PaymentIntent::constructFrom([
        'id' => 'pi_mixed_plan_123',
        'client_secret' => 'pi_mixed_plan_123_secret',
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createPaymentIntent')
        ->once()
        ->withArgs(fn (User $user, int $amount, array $metadata, bool $setupFutureUsage): bool => $amount === 4289
            && $metadata['order_id'] === (string) $order->id
            && $setupFutureUsage === true)
        ->andReturn($paymentIntent);
    $stripeMock->shouldReceive('createCustomerSession')
        ->once()
        ->with('cus_test_123', false)
        ->andReturn(Stripe\CustomerSession::constructFrom([
            'client_secret' => 'cuss_test_secret',
        ]));
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Checkout::class)
        ->assertOk()
        ->assertSet('clientSecret', 'pi_mixed_plan_123_secret')
        ->assertDontSee('Subtotal')
        ->assertSeeInOrder([
            'Payment Plan Items',
            '$50.00',
            'Payment Plan Fee (3%)',
            '$1.50',
            'Pay Today Items',
            '$30.00',
            'Total',
            '$81.50',
        ])
        ->assertSee('4 payments of $12.87')
        ->assertSee('$42.89');
});

it('makes attached payment plan methods redisplayable during checkout', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $existingOrder = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    PaymentPlan::factory()->create([
        'order_id' => $existingOrder->id,
        'stripe_payment_method_id' => 'pm_plan',
    ]);

    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Pending,
    ]);

    OrderItem::factory()->create(['order_id' => $order->id]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createPaymentIntent')
        ->once()
        ->andReturn(Stripe\PaymentIntent::constructFrom([
            'id' => 'pi_test_123',
            'client_secret' => 'pi_test_123_secret',
        ]));
    $stripeMock->shouldReceive('listPaymentMethods')
        ->once()
        ->with('cus_test_123')
        ->andReturn([
            Stripe\PaymentMethod::constructFrom([
                'id' => 'pm_plan',
                'allow_redisplay' => 'limited',
            ]),
            Stripe\PaymentMethod::constructFrom([
                'id' => 'pm_other',
                'allow_redisplay' => 'always',
            ]),
        ]);
    $stripeMock->shouldReceive('makePaymentMethodRedisplayable')
        ->once()
        ->with('pm_plan');
    $stripeMock->shouldReceive('createCustomerSession')
        ->once()
        ->with('cus_test_123', true)
        ->andReturn(Stripe\CustomerSession::constructFrom([
            'client_secret' => 'cuss_test_secret',
        ]));

    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Checkout::class)
        ->assertOk()
        ->assertSet('clientSecret', 'pi_test_123_secret');
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
