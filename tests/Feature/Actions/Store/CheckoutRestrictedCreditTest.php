<?php

declare(strict_types=1);

use App\Actions\Store\CreateOrder;
use App\Contracts\StripeServiceContract;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\CartItem;
use App\Models\Course;
use App\Models\CreditGrant;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 10]);
    $this->courseProduct = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
    $this->standaloneProduct = Product::factory()->standalone()->create(['price' => 3000]);

    $this->app->instance(StripeServiceContract::class, Mockery::mock(StripeServiceContract::class));
});

it('applies restricted credit to eligible items during checkout', function () {
    $grant = CreditGrant::factory()
        ->for($this->user)
        ->amount(5000)
        ->restrictedTo(ProductType::Course)
        ->create();
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->courseProduct->id,
        'quantity' => 1,
    ]);

    $order = app(CreateOrder::class)->handle($this->user);

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->subtotal)->toBe(5000)
        ->and($order->restricted_credit_applied)->toBe(5000)
        ->and($order->total)->toBe(0)
        ->and($grant->refresh()->remaining_amount)->toBe(0);
});

it('does not apply restricted credit to ineligible items', function () {
    $grant = CreditGrant::factory()
        ->for($this->user)
        ->amount(5000)
        ->restrictedTo(ProductType::Course)
        ->create();
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->standaloneProduct->id,
        'quantity' => 1,
    ]);

    $order = app(CreateOrder::class)->handle($this->user);

    expect($order->restricted_credit_applied)->toBe(0)
        ->and($order->total)->toBe(3000)
        ->and($grant->refresh()->remaining_amount)->toBe(5000);
});

it('combines restricted credit and optional unrestricted credit', function () {
    $restrictedGrant = CreditGrant::factory()
        ->for($this->user)
        ->amount(3000)
        ->restrictedTo(ProductType::Course)
        ->create();
    $unrestrictedGrant = CreditGrant::factory()->for($this->user)->amount(3000)->create();
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

    $order = app(CreateOrder::class)->handle($this->user, creditToApply: 3000);

    expect($order->restricted_credit_applied)->toBe(3000)
        ->and($order->credit_applied)->toBe(3000)
        ->and($order->total)->toBe(2000)
        ->and($restrictedGrant->refresh()->remaining_amount)->toBe(0)
        ->and($unrestrictedGrant->refresh()->remaining_amount)->toBe(0);
});

it('partially applies restricted credit when the eligible item is cheaper', function () {
    $grant = CreditGrant::factory()
        ->for($this->user)
        ->amount(10000)
        ->restrictedTo(ProductType::Course)
        ->create();
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->courseProduct->id,
        'quantity' => 1,
    ]);

    $order = app(CreateOrder::class)->handle($this->user);

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->restricted_credit_applied)->toBe(5000)
        ->and($order->total)->toBe(0)
        ->and($grant->refresh()->remaining_amount)->toBe(5000);
});

it('ignores expired restricted credit', function () {
    CreditGrant::factory()
        ->for($this->user)
        ->amount(5000)
        ->restrictedTo(ProductType::Course)
        ->expired()
        ->create();
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->courseProduct->id,
        'quantity' => 1,
    ]);

    $order = app(CreateOrder::class)->handle($this->user);

    expect($order->restricted_credit_applied)->toBe(0)
        ->and($order->total)->toBe(5000);
});
