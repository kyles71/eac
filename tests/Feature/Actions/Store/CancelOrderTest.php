<?php

declare(strict_types=1);

use App\Actions\Store\CancelOrder;
use App\Contracts\StripeServiceContract;
use App\Enums\CreditTransactionType;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\CreditTransaction;
use App\Models\DiscountCode;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Order;
use App\Models\RestrictedCredit;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('cancelPaymentIntent')->byDefault();
    $this->app->instance(StripeServiceContract::class, $mockStripeService);
    $this->mockStripeService = $mockStripeService;
});

it('cancels a pending order and sets status to cancelled', function () {
    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    $action = app(CancelOrder::class);
    $result = $action->handle($order);

    expect($result)->toBeTrue();
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('reverses store credit when cancelling an order', function () {
    $this->user->update(['credit_balance' => 0]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 10000,
        'total' => 7000,
        'credit_applied' => 3000,
    ]);

    $action = app(CancelOrder::class);
    $action->handle($order);

    expect($this->user->refresh()->credit_balance)->toBe(3000);

    $transaction = CreditTransaction::query()
        ->where('user_id', $this->user->id)
        ->where('type', CreditTransactionType::Refund)
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->amount)->toBe(3000)
        ->and($transaction->description)->toContain('Reversed credit');
});

it('reverses restricted credit when cancelling an order', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(5000)
        ->create();

    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(5000)->create();

    $restrictedCredit = RestrictedCredit::factory()->create([
        'user_id' => $this->user->id,
        'gift_card_type_id' => $giftCardType->id,
        'gift_card_id' => $giftCard->id,
        'balance' => 0, // Was fully debited
    ]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 0,
        'restricted_credit_applied' => 5000,
    ]);

    $action = app(CancelOrder::class);
    $action->handle($order);

    expect($restrictedCredit->refresh()->balance)->toBe(5000);
});

it('decrements discount code usage when cancelling an order', function () {
    $discountCode = DiscountCode::factory()->fixedAmount(2000)->create();
    $discountCode->update(['times_used' => 1]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 10000,
        'total' => 8000,
        'discount_code_id' => $discountCode->id,
        'discount_amount' => 2000,
    ]);

    $action = app(CancelOrder::class);
    $action->handle($order);

    expect($discountCode->refresh()->times_used)->toBe(0);
});

it('cancels the stripe payment intent when cancelling an order', function () {
    $this->mockStripeService
        ->shouldReceive('cancelPaymentIntent')
        ->once()
        ->with('pi_test_123');

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_test_123',
    ]);

    $action = app(CancelOrder::class);
    $action->handle($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('handles stripe payment intent cancellation failure gracefully', function () {
    $this->mockStripeService
        ->shouldReceive('cancelPaymentIntent')
        ->once()
        ->andThrow(new Exception('PaymentIntent already cancelled'));

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_test_456',
    ]);

    $action = app(CancelOrder::class);
    $result = $action->handle($order);

    // Should still cancel the order even if Stripe fails
    expect($result)->toBeTrue();
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('skips non-pending orders', function () {
    $order = Order::factory()->completed()->create([
        'user_id' => $this->user->id,
    ]);

    $action = app(CancelOrder::class);
    $result = $action->handle($order);

    expect($result)->toBeFalse();
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);
});

it('skips already cancelled orders', function () {
    $order = Order::factory()->cancelled()->create([
        'user_id' => $this->user->id,
    ]);

    $action = app(CancelOrder::class);
    $result = $action->handle($order);

    expect($result)->toBeFalse();
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('reverses all side effects together when cancelling', function () {
    $this->user->update(['credit_balance' => 0]);

    $discountCode = DiscountCode::factory()->fixedAmount(1000)->create();
    $discountCode->update(['times_used' => 1]);

    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(2000)
        ->create();

    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(2000)->create();

    $restrictedCredit = RestrictedCredit::factory()->create([
        'user_id' => $this->user->id,
        'gift_card_type_id' => $giftCardType->id,
        'gift_card_id' => $giftCard->id,
        'balance' => 0,
    ]);

    $order = Order::factory()->create([
        'user_id' => $this->user->id,
        'status' => OrderStatus::Pending,
        'subtotal' => 10000,
        'total' => 5000,
        'discount_code_id' => $discountCode->id,
        'discount_amount' => 1000,
        'credit_applied' => 2000,
        'restricted_credit_applied' => 2000,
        'stripe_payment_intent_id' => 'pi_test_789',
    ]);

    $this->mockStripeService
        ->shouldReceive('cancelPaymentIntent')
        ->once()
        ->with('pi_test_789');

    $action = app(CancelOrder::class);
    $result = $action->handle($order);

    expect($result)->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->user->refresh()->credit_balance)->toBe(2000)
        ->and($restrictedCredit->refresh()->balance)->toBe(2000)
        ->and($discountCode->refresh()->times_used)->toBe(0);
});
