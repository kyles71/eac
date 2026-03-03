<?php

declare(strict_types=1);

use App\Actions\Store\CreateOrder;
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

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);
});

it('creates an order and returns the order model', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $action = new CreateOrder;
    $order = $action->handle($this->user);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->subtotal)->toBe(10000) // 2 * 5000
        ->and($order->total)->toBe(10000);

    // Verify order items
    $orderItems = OrderItem::query()->where('order_id', $order->id)->get();
    expect($orderItems)->toHaveCount(1)
        ->and($orderItems->first()->product_id)->toBe($this->product->id)
        ->and($orderItems->first()->quantity)->toBe(2)
        ->and($orderItems->first()->unit_price)->toBe(5000)
        ->and($orderItems->first()->total_price)->toBe(10000);
});

it('fails when cart is empty', function () {
    $action = new CreateOrder;
    $action->handle($this->user);
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

    $action = new CreateOrder;
    $action->handle($this->user);
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

    $action = new CreateOrder;
    $order = $action->handle($this->user);

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

    $action = new CreateOrder;
    $order = $action->handle($this->user, $discountCode);

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

    $action = new CreateOrder;
    $order = $action->handle($this->user, $discountCode);

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

    $action = new CreateOrder;
    $order = $action->handle($this->user, $discountCode);

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

    $action = new CreateOrder;
    $order = $action->handle($this->user, creditToApply: 3000);

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

    $action = new CreateOrder;
    $order = $action->handle($this->user, creditToApply: 15000);

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

    $action = new CreateOrder;
    $order = $action->handle($this->user, $discountCode, 5000);

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

    $action = new CreateOrder;
    $order = $action->handle($this->user, creditToApply: 5000);

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

    $action = new CreateOrder;
    $action->handle($this->user, $discountCode);

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

    $action = new CreateOrder;
    $action->handle($this->user, $discountCode);

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

    $action = new CreateOrder;
    $action->handle($this->user, $discountCode);

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

    $action = new CreateOrder;
    $action->handle($this->user, $discountCode);

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

it('stores payment plan template and method on the order', function () {
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

    $action = new CreateOrder;
    $order = $action->handle(
        $this->user,
        paymentPlanTemplate: $template,
        paymentPlanMethod: PaymentPlanMethod::AutoCharge,
    );

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->total)->toBe(10000)
        ->and($order->payment_plan_template_id)->toBe($template->id)
        ->and($order->payment_plan_method)->toBe(PaymentPlanMethod::AutoCharge);
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

    $action = new CreateOrder;
    $order = $action->handle(
        $this->user,
        $discountCode,
        paymentPlanTemplate: $template,
        paymentPlanMethod: PaymentPlanMethod::ManualInvoice,
    );

    expect($order->subtotal)->toBe(10000)
        ->and($order->discount_amount)->toBe(2000)
        ->and($order->total)->toBe(8000)
        ->and($order->payment_plan_template_id)->toBe($template->id)
        ->and($order->payment_plan_method)->toBe(PaymentPlanMethod::ManualInvoice);
});
