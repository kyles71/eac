<?php

declare(strict_types=1);

use App\Actions\Store\CreateCheckoutSession;
use App\Contracts\StripeServiceContract;
use App\Enums\CreditTransactionType;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\CartItem;
use App\Models\Costume;
use App\Models\Course;
use App\Models\CreditTransaction;
use App\Models\DiscountCode;
use App\Models\Enrollment;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlanTemplate;
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

it('applies a percentage discount code to the order', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $discountCode = DiscountCode::factory()->percentage(20)->create();

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_discount',
        'url' => 'https://checkout.stripe.com/discount',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn($mockSession);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $url = $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', $discountCode);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->subtotal)->toBe(10000)
        ->and($order->discount_amount)->toBe(2000)
        ->and($order->total)->toBe(8000)
        ->and($order->discount_code_id)->toBe($discountCode->id);

    // Verify times_used was incremented
    expect($discountCode->refresh()->times_used)->toBe(1);
});

it('applies a fixed amount discount code to the order', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(3000)->create();

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_fixed',
        'url' => 'https://checkout.stripe.com/fixed',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn($mockSession);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', $discountCode);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->subtotal)->toBe(10000)
        ->and($order->discount_amount)->toBe(3000)
        ->and($order->total)->toBe(7000);
});

it('completes order immediately when discount covers full amount', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(10000)->create();

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createCheckoutSession');

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $url = $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', $discountCode);

    // Should redirect to success page with order_id
    expect($url)->toContain('order_id=');

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->total)->toBe(0)
        ->and($order->discount_amount)->toBe(5000);

    // Verify enrollment was created
    expect(Enrollment::query()->where('user_id', $this->user->id)->count())->toBe(1);

    // Verify cart was cleared
    expect(CartItem::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('applies store credit to reduce the order total', function () {
    $this->user->update(['credit_balance' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_credit',
        'url' => 'https://checkout.stripe.com/credit',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn($mockSession);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', null, 3000);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->subtotal)->toBe(10000)
        ->and($order->credit_applied)->toBe(3000)
        ->and($order->total)->toBe(7000);

    // Verify credit was debited
    expect($this->user->refresh()->credit_balance)->toBe(0);

    // Verify credit transaction was created
    $transaction = CreditTransaction::query()->where('user_id', $this->user->id)->first();
    expect($transaction->amount)->toBe(-3000)
        ->and($transaction->type)->toBe(CreditTransactionType::CheckoutDebit);
});

it('completes order immediately when credit covers full amount', function () {
    $this->user->update(['credit_balance' => 15000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createCheckoutSession');

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $url = $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', null, 15000);

    expect($url)->toContain('order_id=');

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->credit_applied)->toBe(5000)
        ->and($order->total)->toBe(0);

    // Verify credit was debited (only what was needed, not the full 15000)
    expect($this->user->refresh()->credit_balance)->toBe(10000);

    // Verify enrollment was created
    expect(Enrollment::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('combines discount code and credit to cover the full amount', function () {
    $this->user->update(['credit_balance' => 5000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    // 50% discount on 10000 = 5000 remaining, then 5000 credit covers it
    $discountCode = DiscountCode::factory()->percentage(50)->create();

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createCheckoutSession');

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $url = $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', $discountCode, 5000);

    expect($url)->toContain('order_id=');

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->subtotal)->toBe(10000)
        ->and($order->discount_amount)->toBe(5000)
        ->and($order->credit_applied)->toBe(5000)
        ->and($order->total)->toBe(0);

    expect($this->user->refresh()->credit_balance)->toBe(0);
});

it('does not apply more credit than the user has', function () {
    $this->user->update(['credit_balance' => 2000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_limited_credit',
        'url' => 'https://checkout.stripe.com/limited',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn($mockSession);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', null, 5000);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    // Should only apply 2000 (user's actual balance), not 5000
    expect($order->credit_applied)->toBe(2000)
        ->and($order->total)->toBe(8000);

    expect($this->user->refresh()->credit_balance)->toBe(0);
});

it('fulfills gift cards when order completes at zero total', function () {
    $giftCardType = GiftCardType::factory()->denomination(5000)->create();
    $gcProduct = Product::factory()->forGiftCardType($giftCardType)->create(['price' => 5000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $gcProduct->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(10000)->create();

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createCheckoutSession');

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', $discountCode);

    // Gift card should have been created
    expect(GiftCard::query()->where('purchased_by_user_id', $this->user->id)->count())->toBe(1);

    $giftCard = GiftCard::query()->where('purchased_by_user_id', $this->user->id)->first();
    expect($giftCard->initial_amount)->toBe(5000)
        ->and($giftCard->remaining_amount)->toBe(5000)
        ->and($giftCard->is_active)->toBeTrue();
});

it('leaves costume order items as pending in zero total order', function () {
    $costume = Costume::factory()->create();
    $costumeProduct = Product::factory()->forCostume($costume)->create(['price' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $costumeProduct->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(10000)->create();

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createCheckoutSession');
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', $discountCode);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed);

    $orderItem = OrderItem::query()->where('order_id', $order->id)->first();
    expect($orderItem->status)->toBe(OrderItemStatus::Pending);
});

it('leaves standalone order items as pending in zero total order', function () {
    $standaloneProduct = Product::factory()->standalone()->create(['price' => 2000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $standaloneProduct->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(10000)->create();

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createCheckoutSession');
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', $discountCode);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed);

    $orderItem = OrderItem::query()->where('order_id', $order->id)->first();
    expect($orderItem->status)->toBe(OrderItemStatus::Pending);
});

it('marks course items fulfilled and leaves costume items pending in mixed zero total order', function () {
    $costume = Costume::factory()->create();
    $costumeProduct = Product::factory()->forCostume($costume)->create(['price' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $costumeProduct->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(20000)->create();

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createCheckoutSession');
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', $discountCode);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed);

    $courseOrderItem = OrderItem::query()
        ->where('order_id', $order->id)
        ->where('product_id', $this->product->id)
        ->first();
    expect($courseOrderItem->status)->toBe(OrderItemStatus::Fulfilled);

    $costumeOrderItem = OrderItem::query()
        ->where('order_id', $order->id)
        ->where('product_id', $costumeProduct->id)
        ->first();
    expect($costumeOrderItem->status)->toBe(OrderItemStatus::Pending);

    // Course enrollment should exist
    expect(Enrollment::query()->where('course_id', $this->course->id)->count())->toBe(1);
});

it('creates checkout session with payment plan charging first installment only', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
        'min_price' => 1000,
        'max_price' => 50000,
    ]);

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_plan',
        'url' => 'https://checkout.stripe.com/plan',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->withArgs(function ($user, $lineItems, $successUrl, $cancelUrl, $metadata, $setupFutureUsage) use ($template) {
            // First installment of 10000 split 3 ways: 3334 + 3333 + 3333
            expect($lineItems)->toHaveCount(1)
                ->and($lineItems[0]['price_data']['unit_amount'])->toBe(3334)
                ->and($lineItems[0]['quantity'])->toBe(1)
                ->and($lineItems[0]['price_data']['product_data']['name'])->toContain('Installment 1 of 3')
                ->and($metadata['payment_plan_template_id'])->toBe((string) $template->id)
                ->and($metadata['payment_plan_method'])->toBe(PaymentPlanMethod::AutoCharge->value)
                ->and($setupFutureUsage)->toBeTrue();

            return true;
        })
        ->andReturn($mockSession);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $url = $action->handle(
        $this->user,
        'https://example.com/success',
        'https://example.com/cancel',
        paymentPlanTemplate: $template,
        paymentPlanMethod: PaymentPlanMethod::AutoCharge,
    );

    expect($url)->toBe('https://checkout.stripe.com/plan');

    // Full order total should still be 10000
    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->total)->toBe(10000);
});

it('combines discount code with payment plan', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $discountCode = DiscountCode::factory()->percentage(20)->create(); // 20% of 10000 = 2000 off
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
        'min_price' => 1000,
        'max_price' => 50000,
    ]);

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_plan_disc',
        'url' => 'https://checkout.stripe.com/plan_disc',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->withArgs(function ($user, $lineItems, $successUrl, $cancelUrl, $metadata, $setupFutureUsage) {
            // Total after discount: 8000. First installment of 4: 2000 each, no remainder
            expect($lineItems[0]['price_data']['unit_amount'])->toBe(2000)
                ->and($setupFutureUsage)->toBeTrue();

            return true;
        })
        ->andReturn($mockSession);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle(
        $this->user,
        'https://example.com/success',
        'https://example.com/cancel',
        $discountCode,
        paymentPlanTemplate: $template,
        paymentPlanMethod: PaymentPlanMethod::ManualInvoice,
    );

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->subtotal)->toBe(10000)
        ->and($order->discount_amount)->toBe(2000)
        ->and($order->total)->toBe(8000);
});
