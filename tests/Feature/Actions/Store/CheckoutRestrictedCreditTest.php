<?php

declare(strict_types=1);

use App\Actions\Store\CreateCheckoutSession;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestrictedCredit;
use App\Models\User;
use Stripe\Checkout\Session as StripeSession;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 10]);
    $this->courseProduct = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
    $this->standaloneProduct = Product::factory()->standalone()->create(['price' => 3000]);
});

it('applies restricted credit to eligible items during checkout', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(5000)
        ->create();

    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(5000)->create();

    RestrictedCredit::factory()->create([
        'user_id' => $this->user->id,
        'gift_card_type_id' => $giftCardType->id,
        'gift_card_id' => $giftCard->id,
        'balance' => 5000,
    ]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->courseProduct->id,
        'quantity' => 1,
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createCheckoutSession');
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $url = $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel');

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->subtotal)->toBe(5000)
        ->and($order->restricted_credit_applied)->toBe(5000)
        ->and($order->total)->toBe(0);

    // Verify restricted credit was debited
    $restrictedCredit = RestrictedCredit::query()
        ->where('user_id', $this->user->id)
        ->first();

    expect($restrictedCredit->balance)->toBe(0);
});

it('does not apply restricted credit to ineligible items', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(5000)
        ->create();

    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(5000)->create();

    RestrictedCredit::factory()->create([
        'user_id' => $this->user->id,
        'gift_card_type_id' => $giftCardType->id,
        'gift_card_id' => $giftCard->id,
        'balance' => 5000,
    ]);

    // Cart has only a standalone product (not a Course)
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->standaloneProduct->id,
        'quantity' => 1,
    ]);

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_restricted',
        'url' => 'https://checkout.stripe.com/restricted',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn($mockSession);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel');

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->restricted_credit_applied)->toBe(0)
        ->and($order->total)->toBe(3000);

    // Restricted credit should remain untouched
    $restrictedCredit = RestrictedCredit::query()
        ->where('user_id', $this->user->id)
        ->first();

    expect($restrictedCredit->balance)->toBe(5000);
});

it('combines restricted credit and unrestricted credit', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(3000)
        ->create();

    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(3000)->create();

    RestrictedCredit::factory()->create([
        'user_id' => $this->user->id,
        'gift_card_type_id' => $giftCardType->id,
        'gift_card_id' => $giftCard->id,
        'balance' => 3000,
    ]);

    $this->user->update(['credit_balance' => 3000]);

    // Cart: course product (5000) + standalone (3000) = 8000
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->courseProduct->id,
        'quantity' => 1,
    ]);
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->standaloneProduct->id,
        'quantity' => 1,
    ]);

    $mockSession = StripeSession::constructFrom([
        'id' => 'cs_test_combined',
        'url' => 'https://checkout.stripe.com/combined',
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn($mockSession);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel', null, 3000);

    $order = Order::query()->where('user_id', $this->user->id)->first();

    // Restricted credit (3000) applied to the course product
    expect($order->restricted_credit_applied)->toBe(3000)
        // Unrestricted credit (3000) applied to remaining
        ->and($order->credit_applied)->toBe(3000)
        // Total: 8000 - 3000 restricted - 3000 unrestricted = 2000
        ->and($order->total)->toBe(2000);

    // Verify restricted credit was fully consumed
    expect(RestrictedCredit::query()->where('user_id', $this->user->id)->first()->balance)->toBe(0);

    // Verify unrestricted credit was consumed
    expect($this->user->refresh()->credit_balance)->toBe(0);
});

it('partially applies restricted credit when item is cheaper than credit', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(10000)
        ->create();

    $giftCard = GiftCard::factory()->forType($giftCardType)->amount(10000)->create();

    RestrictedCredit::factory()->create([
        'user_id' => $this->user->id,
        'gift_card_type_id' => $giftCardType->id,
        'gift_card_id' => $giftCard->id,
        'balance' => 10000,
    ]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->courseProduct->id,
        'quantity' => 1,
    ]);

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldNotReceive('createCheckoutSession');
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $action = app(CreateCheckoutSession::class);
    $action->handle($this->user, 'https://example.com/success', 'https://example.com/cancel');

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->restricted_credit_applied)->toBe(5000)
        ->and($order->total)->toBe(0);

    // Remaining restricted credit should be 5000
    $restrictedCredit = RestrictedCredit::query()
        ->where('user_id', $this->user->id)
        ->first();

    expect($restrictedCredit->balance)->toBe(5000);
});
