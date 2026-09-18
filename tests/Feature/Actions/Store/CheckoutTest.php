<?php

declare(strict_types=1);

use App\Actions\Store\CreateOrder;
use App\Contracts\StripeServiceContract;
use App\Enums\CreditTransactionType;
use App\Enums\FulfillmentWorkflow;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\CartItem;
use App\Models\CompetitionSeason;
use App\Models\CompetitionTeam;
use App\Models\Course;
use App\Models\CreditGrant;
use App\Models\CreditTransaction;
use App\Models\DiscountCode;
use App\Models\Enrollment;
use App\Models\Gear;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\LegalDocumentAcceptance;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\ProductEarlyAccessWindow;
use App\Models\User;
use App\Support\LegalDocuments\PaymentPlanTerms;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->course = Course::factory()->create(['capacity' => 5]);
    $this->product = Product::factory()->forCourse($this->course)->create(['price' => 5000]);

    publishPaymentPlanTermsForCheckoutTest();

    $this->app->instance(StripeServiceContract::class, Mockery::mock(StripeServiceContract::class));
});

it('creates an order and returns the order model', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $action = app(CreateOrder::class);
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

it('creates separate order items for custom gift card amounts', function () {
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $giftCardProduct->id,
        'quantity' => 2,
        'custom_gift_card_amount' => 7500,
    ]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $giftCardProduct->id,
        'quantity' => 1,
        'custom_gift_card_amount' => 2500,
    ]);

    $order = app(CreateOrder::class)->handle($this->user);
    $orderItems = $order->orderItems()->orderBy('unit_price')->get();

    expect($order->subtotal)->toBe(17500)
        ->and($orderItems)->toHaveCount(2)
        ->and($orderItems[0]->unit_price)->toBe(2500)
        ->and($orderItems[0]->total_price)->toBe(2500)
        ->and($orderItems[0]->custom_gift_card_amount)->toBe(2500)
        ->and($orderItems[1]->unit_price)->toBe(7500)
        ->and($orderItems[1]->total_price)->toBe(15000)
        ->and($orderItems[1]->custom_gift_card_amount)->toBe(7500);
});

it('fails when cart is empty', function () {
    $action = app(CreateOrder::class);
    $action->handle($this->user);
})->throws(InvalidArgumentException::class, 'Your cart is empty.');

it('fails when a cart product became unavailable before checkout', function () {
    $this->product->update(['name' => 'Jazz Shoes']);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $this->product->update(['available_until' => now()->subMinute()]);

    $action = app(CreateOrder::class);
    $action->handle($this->user);
})->throws(InvalidArgumentException::class, '"Jazz Shoes" is no longer available for purchase.');

it('fails when a cart product gains unmet purchase eligibility requirements', function () {
    $season = CompetitionSeason::factory()->current()->create();
    $requiredTeam = CompetitionTeam::factory()->for($season, 'season')->create();
    $this->product->update(['name' => 'Competition Jacket']);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $this->product->requiredCompetitionTeams()->attach($requiredTeam);

    $action = app(CreateOrder::class);
    $action->handle($this->user);
})->throws(InvalidArgumentException::class, '"Competition Jacket" is limited to its configured purchase audience.');

it('requires every configured group category unless the customer is directly assigned', function () {
    $requiredCourse = Course::factory()->create();
    $season = CompetitionSeason::factory()->current()->create();
    $unmatchedTeam = CompetitionTeam::factory()->for($season, 'season')->create();
    $this->product->requiredCourses()->attach($requiredCourse);
    $this->product->requiredCompetitionTeams()->attach($unmatchedTeam);

    Enrollment::factory()->create([
        'course_id' => $requiredCourse->id,
        'user_id' => $this->user->id,
    ]);
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
    ]);

    expect(fn () => app(CreateOrder::class)->handle($this->user))
        ->toThrow(InvalidArgumentException::class, 'limited to its configured purchase audience');

    $this->product->assignedUsers()->attach($this->user);

    expect(app(CreateOrder::class)->handle($this->user))->toBeInstanceOf(Order::class);
});

it('checks early access window timing at checkout', function () {
    $this->product->update([
        'name' => 'Early Window Course',
        'available_from' => now()->addDay(),
    ]);
    $window = ProductEarlyAccessWindow::factory()
        ->for($this->product)
        ->create([
            'available_from' => now()->subHour(),
            'available_until' => now()->addHour(),
        ]);
    $window->users()->attach($this->user);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    expect(app(CreateOrder::class)->handle($this->user))->toBeInstanceOf(Order::class);

    $window->update(['available_until' => now()->subMinute()]);

    app(CreateOrder::class)->handle($this->user);
})->throws(InvalidArgumentException::class, '"Early Window Course" is not available yet.');

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

    $action = app(CreateOrder::class);
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

    $action = app(CreateOrder::class);
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

    $action = app(CreateOrder::class);
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

    $action = app(CreateOrder::class);
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

    $action = app(CreateOrder::class);
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
    CreditGrant::factory()->for($this->user)->amount(3000)->create();

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $action = app(CreateOrder::class);
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
    Mail::fake();
    CreditGrant::factory()->for($this->user)->amount(15000)->create();

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $action = app(CreateOrder::class);
    $order = $action->handle($this->user, creditToApply: 15000);
    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->credit_applied)->toBe(5000)
        ->and($order->total)->toBe(0)
        ->and($order->receipt_queued_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, 1);

    // Verify credit was debited (only what was needed, not the full 15000)
    expect($this->user->refresh()->credit_balance)->toBe(10000);

    // Verify enrollment was created
    expect(Enrollment::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('combines discount code and credit to cover the full amount', function () {
    CreditGrant::factory()->for($this->user)->amount(5000)->create();

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    // 50% discount on 10000 = 5000 remaining, then 5000 credit covers it
    $discountCode = DiscountCode::factory()->percentage(50)->create();

    $action = app(CreateOrder::class);
    $order = $action->handle($this->user, $discountCode, 5000);

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->subtotal)->toBe(10000)
        ->and($order->discount_amount)->toBe(5000)
        ->and($order->credit_applied)->toBe(5000)
        ->and($order->total)->toBe(0);

    expect($this->user->refresh()->credit_balance)->toBe(0);
});

it('does not apply more credit than the user has', function () {
    CreditGrant::factory()->for($this->user)->amount(2000)->create();

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $action = app(CreateOrder::class);
    $order = $action->handle($this->user, creditToApply: 5000);

    // Should only apply 2000 (user's actual balance), not 5000
    expect($order->credit_applied)->toBe(2000)
        ->and($order->total)->toBe(8000);

    expect($this->user->refresh()->credit_balance)->toBe(0);
});

it('fulfills gift cards when order completes at zero total', function () {
    Mail::fake();
    $giftCardType = GiftCardType::factory()->denomination(5000)->create();
    $gcProduct = Product::factory()->forGiftCardType($giftCardType)->create(['price' => 5000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $gcProduct->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(10000)->create();

    $action = app(CreateOrder::class);
    $action->handle($this->user, $discountCode);

    // Gift card should have been created
    expect(GiftCard::query()->where('purchased_by_user_id', $this->user->id)->count())->toBe(1);

    $giftCard = GiftCard::query()->where('purchased_by_user_id', $this->user->id)->first();
    expect($giftCard->initial_amount)->toBe(5000)
        ->and($giftCard->remaining_amount)->toBe(5000)
        ->and($giftCard->is_active)->toBeTrue()
        ->and($giftCard->delivery_email_queued_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, 2);
    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'gift-card-delivery'
        && $mail->hasTo($this->user->email)
        && $mail->usesMailer('transactional'));
});

it('fulfills custom amount gift cards when order completes at zero total', function () {
    Mail::fake();
    $giftCardType = GiftCardType::factory()
        ->denomination(5000)
        ->customAmount(500)
        ->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create();

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $giftCardProduct->id,
        'quantity' => 1,
        'custom_gift_card_amount' => 7500,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(10000)->create();

    $order = app(CreateOrder::class)->handle($this->user, $discountCode);
    $giftCard = GiftCard::query()->where('order_id', $order->id)->firstOrFail();

    expect($giftCard->initial_amount)->toBe(7500)
        ->and($giftCard->remaining_amount)->toBe(7500)
        ->and($giftCard->delivery_email_queued_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'gift-card-delivery'
        && $mail->hasTo($this->user->email)
        && $mail->usesMailer('transactional'));
});

it('leaves gear order items as pending in zero total order', function () {
    $gear = Gear::factory()->create();
    $gearProduct = Product::factory()->forGear($gear)->create(['price' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $gearProduct->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(10000)->create();

    $action = app(CreateOrder::class);
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

    $action = app(CreateOrder::class);
    $action->handle($this->user, $discountCode);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed);

    $orderItem = OrderItem::query()->where('order_id', $order->id)->first();
    expect($orderItem->status)->toBe(OrderItemStatus::Pending);
});

it('snapshots the selected fulfillment workflow on the order item', function (): void {
    $product = Product::factory()->standalone()->create([
        'price' => 2000,
        'fulfillment_workflow' => FulfillmentWorkflow::ScheduledEvent,
    ]);
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $order = app(CreateOrder::class)->handle($this->user);
    $orderItem = $order->orderItems()->firstOrFail();
    $product->update(['fulfillment_workflow' => FulfillmentWorkflow::Manual]);

    expect($orderItem->fulfillment_workflow)->toBe(FulfillmentWorkflow::ScheduledEvent)
        ->and($orderItem->refresh()->fulfillment_workflow)->toBe(FulfillmentWorkflow::ScheduledEvent)
        ->and($product->refresh()->fulfillment_workflow)->toBe(FulfillmentWorkflow::Manual);
});

it('marks course items fulfilled and leaves gear items pending in mixed zero total order', function () {
    $gear = Gear::factory()->create();
    $gearProduct = Product::factory()->forGear($gear)->create(['price' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $gearProduct->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(20000)->create();

    $action = app(CreateOrder::class);
    $action->handle($this->user, $discountCode);

    $order = Order::query()->where('user_id', $this->user->id)->first();
    expect($order->status)->toBe(OrderStatus::Completed);

    $courseOrderItem = OrderItem::query()
        ->where('order_id', $order->id)
        ->where('product_id', $this->product->id)
        ->first();
    expect($courseOrderItem->status)->toBe(OrderItemStatus::Fulfilled);

    $gearOrderItem = OrderItem::query()
        ->where('order_id', $order->id)
        ->where('product_id', $gearProduct->id)
        ->first();
    expect($gearOrderItem->status)->toBe(OrderItemStatus::Pending);

    // Course enrollment should exist
    expect(Enrollment::query()->where('course_id', $this->course->id)->count())->toBe(1);
});

it('stores payment plan details and accepted terms on the order', function () {
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

    $action = app(CreateOrder::class);
    $order = $action->handle(
        $this->user,
        paymentPlanTemplate: $template,
    );

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->payment_plan_fee)->toBe(300)
        ->and($order->payment_plan_principal)->toBe(10000)
        ->and($order->payment_plan_subtotal)->toBe(10000)
        ->and($order->total)->toBe(10300)
        ->and($order->payment_plan_template_id)->toBe($template->id)
        ->and($order->payment_plan_terms_version_id)->not->toBeNull();

    expect(LegalDocumentAcceptance::query()
        ->where('user_id', $this->user->id)
        ->where('acceptable_type', $order->getMorphClass())
        ->where('acceptable_id', $order->id)
        ->exists())->toBeTrue();
});

it('combines discount code with auto-charge payment plan', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 2,
    ]);

    $discountCode = DiscountCode::factory()->percentage(20)->create(); // 20% of 10000 = 2000 off
    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 4,
        'min_price' => 9000,
        'max_price' => 50000,
    ]);

    $action = app(CreateOrder::class);
    $order = $action->handle(
        $this->user,
        $discountCode,
        paymentPlanTemplate: $template,
    );

    expect($order->subtotal)->toBe(10000)
        ->and($order->discount_amount)->toBe(2000)
        ->and($order->payment_plan_fee)->toBe(240)
        ->and($order->payment_plan_principal)->toBe(8000)
        ->and($order->payment_plan_subtotal)->toBe(10000)
        ->and($order->payment_plan_discount_amount)->toBe(2000)
        ->and($order->total)->toBe(8240)
        ->and($order->payment_plan_template_id)->toBe($template->id);
});

it('stores only the eligible mixed cart balance on the payment plan principal', function () {
    $standaloneProduct = Product::factory()->standalone()->create(['price' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $standaloneProduct->id,
        'quantity' => 1,
    ]);

    $template = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'number_of_installments' => 4,
        'min_price' => 1000,
        'max_price' => 10000,
    ]);

    $order = app(CreateOrder::class)->handle(
        $this->user,
        paymentPlanTemplate: $template,
    );

    expect($order->subtotal)->toBe(8000)
        ->and($order->payment_plan_subtotal)->toBe(5000)
        ->and($order->payment_plan_principal)->toBe(5000)
        ->and($order->payment_plan_fee)->toBe(150)
        ->and($order->total)->toBe(8150)
        ->and($order->payInFullItemsSubtotal())->toBe(3000)
        ->and($order->payInFullAmount())->toBe(3000)
        ->and($order->amountPaidAtCheckout())->toBe(4289);
});

it('applies discounts and store credit to mixed cart payment plan items before pay today items', function () {
    $standaloneProduct = Product::factory()->standalone()->create(['price' => 3000]);
    CreditGrant::factory()->for($this->user)->amount(2000)->create();

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $standaloneProduct->id,
        'quantity' => 1,
    ]);

    $discountCode = DiscountCode::factory()->fixedAmount(1000)->create();
    $template = PaymentPlanTemplate::factory()->create([
        'product_type' => ProductType::Course,
        'number_of_installments' => 4,
        'min_price' => 1000,
        'max_price' => 10000,
    ]);

    $order = app(CreateOrder::class)->handle(
        $this->user,
        $discountCode,
        creditToApply: 2000,
        paymentPlanTemplate: $template,
    );

    expect($order->discount_amount)->toBe(1000)
        ->and($order->credit_applied)->toBe(2000)
        ->and($order->payment_plan_subtotal)->toBe(5000)
        ->and($order->payment_plan_discount_amount)->toBe(1000)
        ->and($order->payment_plan_credit_applied)->toBe(2000)
        ->and($order->payment_plan_principal)->toBe(2000)
        ->and($order->payment_plan_fee)->toBe(60)
        ->and($order->total)->toBe(5060)
        ->and($order->payInFullItemsSubtotal())->toBe(3000)
        ->and($order->payInFullAmount())->toBe(3000)
        ->and($order->amountPaidAtCheckout())->toBe(3515);
});

it('cancels existing pending orders before creating a new one', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    // Create a first order
    $action = app(CreateOrder::class);
    $firstOrder = $action->handle($this->user);

    expect($firstOrder->status)->toBe(OrderStatus::Pending);

    // Add a different product to cart (unique constraint on user_id, product_id)
    $course2 = Course::factory()->create(['capacity' => 10]);
    $product2 = Product::factory()->forCourse($course2)->create(['price' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $product2->id,
        'quantity' => 1,
    ]);

    // Create a second order — this should cancel the first
    $secondOrder = $action->handle($this->user);

    expect($secondOrder->status)->toBe(OrderStatus::Pending)
        ->and($secondOrder->id)->not->toBe($firstOrder->id);

    // First order should now be cancelled
    expect($firstOrder->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('does not cancel processing orders when creating a new one', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    // Create a first order and mark it as processing (payment submitted to Stripe)
    $action = app(CreateOrder::class);
    $firstOrder = $action->handle($this->user);
    $firstOrder->update(['status' => OrderStatus::Processing]);

    // Add a different product to cart
    $course2 = Course::factory()->create(['capacity' => 10]);
    $product2 = Product::factory()->forCourse($course2)->create(['price' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $product2->id,
        'quantity' => 1,
    ]);

    // Create a second order — this should NOT cancel the processing order
    $secondOrder = $action->handle($this->user);

    expect($secondOrder->status)->toBe(OrderStatus::Pending)
        ->and($firstOrder->refresh()->status)->toBe(OrderStatus::Processing);
});

it('reverses store credit from previous pending order when creating a new one', function () {
    CreditGrant::factory()->for($this->user)->amount(3000)->create();

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $action = app(CreateOrder::class);
    $firstOrder = $action->handle($this->user, creditToApply: 3000);

    expect($firstOrder->credit_applied)->toBe(3000)
        ->and($this->user->refresh()->credit_balance)->toBe(0);

    // Add a different product to cart
    $course2 = Course::factory()->create(['capacity' => 10]);
    $product2 = Product::factory()->forCourse($course2)->create(['price' => 3000]);

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $product2->id,
        'quantity' => 1,
    ]);

    // Create a new order with credit — old credit should be reversed first
    $secondOrder = $action->handle($this->user, creditToApply: 3000);

    // First order cancelled, credit restored then re-debited on second order
    expect($firstOrder->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($secondOrder->credit_applied)->toBe(3000)
        ->and($this->user->refresh()->credit_balance)->toBe(0);
});

it('does not clear cart when order requires payment', function () {
    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $action = app(CreateOrder::class);
    $action->handle($this->user);

    // Cart items should still exist — user may abandon checkout
    expect($this->user->cartItems()->count())->toBe(1);
});

it('clears cart items when order has zero balance', function () {
    $discountCode = DiscountCode::factory()->percentage(100)->create();

    CartItem::factory()->create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
    ]);

    $action = app(CreateOrder::class);
    $action->handle($this->user, discountCode: $discountCode);

    // Cart should be cleared since the order completed immediately
    expect($this->user->cartItems()->count())->toBe(0);
});

function publishPaymentPlanTermsForCheckoutTest(): void
{
    if (PaymentPlanTerms::currentVersion() !== null) {
        return;
    }

    PaymentPlanTerms::document()?->publishVersion(
        title: 'Payment Plan Terms & Conditions',
        content: '<p>Test payment plan terms.</p>',
    );
}
