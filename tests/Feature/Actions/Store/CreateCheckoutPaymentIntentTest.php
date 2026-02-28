<?php

declare(strict_types=1);

use App\Actions\Store\CreateCheckoutPaymentIntent;
use App\Contracts\StripeServiceContract;
use App\Enums\CreditTransactionType;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanMethod;
use App\Models\CartItem;
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
use Stripe\PaymentIntent;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('creates an order and returns a payment intent client secret', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_123',
        'client_secret' => 'pi_test_123_secret_abc',
        'status' => 'requires_payment_method',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createPaymentIntent')
        ->once()
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutPaymentIntent::class);
    $result = $action->handle($this->user);

    expect($result['zero_total'])->toBeFalse()
        ->and($result['client_secret'])->toBe('pi_test_123_secret_abc')
        ->and($result['checkout_amount'])->toBe(10000)
        ->and($result['subtotal'])->toBe(10000)
        ->and($result['total'])->toBe(10000)
        ->and($result['cart_summary'])->toHaveCount(1);

    // Verify order was created
    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order)->not->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->subtotal)->toBe(10000)
        ->and($order->total)->toBe(10000)
        ->and($order->stripe_payment_intent_id)->toBe('pi_test_123');

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

    $action = app(CreateCheckoutPaymentIntent::class);
    $action->handle($this->user);
})->throws(InvalidArgumentException::class, 'Your cart is empty.');

it('fails when course capacity is insufficient', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 3,
    ]);

    for ($i = 0; $i < 5; $i++) {
        Enrollment::factory()->create(['course_id' => $this->course->id]);
    }

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutPaymentIntent::class);
    $action->handle($this->user);
})->throws(InvalidArgumentException::class);

it('applies a discount code and reduces the checkout amount', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $discountCode = DiscountCode::factory()->percentage(20)->create();

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_discount',
        'client_secret' => 'pi_test_discount_secret',
        'status' => 'requires_payment_method',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createPaymentIntent')
        ->once()
        ->withArgs(function ($user, $amount) {
            expect($amount)->toBe(8000);

            return true;
        })
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutPaymentIntent::class);
    $result = $action->handle($this->user, $discountCode);

    expect($result['total'])->toBe(8000)
        ->and($result['checkout_amount'])->toBe(8000)
        ->and($result['discount_display'])->toContain($discountCode->code);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->discount_amount)->toBe(2000)
        ->and($order->discount_code_id)->toBe($discountCode->id);
});

it('completes order immediately when fully covered by discount', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(10000)->create();

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createPaymentIntent');

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutPaymentIntent::class);
    $result = $action->handle($this->user, $discountCode);

    expect($result['zero_total'])->toBeTrue()
        ->and($result['client_secret'])->toBeNull()
        ->and($result['checkout_amount'])->toBe(0);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->total)->toBe(0);

    expect(Enrollment::query()->where('user_id', $this->user->id)->count())->toBe(1);
    expect(CartItem::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('applies store credit and reduces the checkout amount', function () {
    $this->user->update(['credit_balance' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_credit',
        'client_secret' => 'pi_test_credit_secret',
        'status' => 'requires_payment_method',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createPaymentIntent')
        ->once()
        ->withArgs(function ($user, $amount) {
            expect($amount)->toBe(7000);

            return true;
        })
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutPaymentIntent::class);
    $result = $action->handle($this->user, null, 3000);

    expect($result['total'])->toBe(7000)
        ->and($result['credit_display'])->toContain('$30.00');

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->credit_applied)->toBe(3000);

    expect($this->user->refresh()->credit_balance)->toBe(0);

    $transaction = CreditTransaction::query()->where('user_id', $this->user->id)->first();
    expect($transaction->amount)->toBe(-3000)
        ->and($transaction->type)->toBe(CreditTransactionType::CheckoutDebit);
});

it('creates payment intent with payment plan charging first installment only', function () {
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

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_plan',
        'client_secret' => 'pi_test_plan_secret',
        'status' => 'requires_payment_method',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createPaymentIntent')
        ->once()
        ->withArgs(function ($user, $amount, $metadata, $setupFutureUsage) use ($template) {
            // First installment of 10000 split 3 ways: 3334 + 3333 + 3333
            expect($amount)->toBe(3334)
                ->and($metadata['payment_plan_template_id'])->toBe((string) $template->id)
                ->and($metadata['payment_plan_method'])->toBe(PaymentPlanMethod::AutoCharge->value)
                ->and($setupFutureUsage)->toBeTrue();

            return true;
        })
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutPaymentIntent::class);
    $result = $action->handle(
        $this->user,
        paymentPlanTemplate: $template,
        paymentPlanMethod: PaymentPlanMethod::AutoCharge,
    );

    expect($result['checkout_amount'])->toBe(3334)
        ->and($result['total'])->toBe(10000)
        ->and($result['payment_plan_display'])->toContain('3 installments');
});

it('returns cart summary with correct items', function () {
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

    $mockPaymentIntent = PaymentIntent::constructFrom([
        'id' => 'pi_test_multi',
        'client_secret' => 'pi_test_multi_secret',
        'status' => 'requires_payment_method',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createPaymentIntent')
        ->once()
        ->andReturn($mockPaymentIntent);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutPaymentIntent::class);
    $result = $action->handle($this->user);

    expect($result['cart_summary'])->toHaveCount(2)
        ->and($result['subtotal'])->toBe(20000)
        ->and($result['total'])->toBe(20000);
});
