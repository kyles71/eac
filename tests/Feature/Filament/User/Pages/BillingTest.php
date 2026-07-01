<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanStatus;
use App\Enums\ProductType;
use App\Filament\User\Pages\Billing;
use App\Filament\User\Pages\BillingCreditGrantsTable;
use App\Models\CreditGrant;
use App\Models\CreditTransaction;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Installment;
use App\Models\LegalDocumentVersion;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\Product;
use App\Models\ProductQuestionAnswer;
use App\Models\User;
use App\Support\LegalDocuments\PaymentPlanTerms;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Livewire\Livewire;
use Stripe\Customer;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('user');
});

it('can render the billing page', function () {
    livewire(Billing::class)
        ->assertOk()
        ->assertSee('Overview')
        ->assertSee('Orders & Receipts')
        ->assertSee('Payment Methods');
});

it('shows only the authenticated users orders', function () {
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'total' => 5000,
    ]);

    $otherOrder = Order::factory()->completed()->create([
        'user_id' => User::factory(),
        'total' => 7000,
    ]);

    livewire(Billing::class)
        ->assertSee("Order #{$order->id}")
        ->assertDontSee("Order #{$otherOrder->id}");
});

it('resends a completed order receipt from billing', function () {
    Mail::fake();
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'receipt_queued_at' => now()->subDay(),
    ]);
    OrderItem::factory()->fulfilled()->create(['order_id' => $order->id]);

    livewire(Billing::class)
        ->callAction(
            TestAction::make("resend_receipt_{$order->id}")->schemaComponent(true, 'content'),
        )
        ->assertNotified('Receipt email queued');

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->hasTo(auth()->user()->email)
        && $mail->usesMailer('transactional'));
});

it('shows purchaser answers in the billing receipt modal', function (): void {
    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'subtotal' => 5000,
        'total' => 5000,
        'discount_amount' => 0,
        'restricted_credit_applied' => 0,
        'credit_applied' => 0,
        'payment_plan_fee' => 0,
    ]);
    $product = Product::factory()->create(['name' => 'Competition Shirt']);
    $orderItem = OrderItem::factory()->fulfilled()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);
    ProductQuestionAnswer::factory()->create([
        'order_item_id' => $orderItem->id,
        'product_question_id' => null,
        'question' => 'Dancer name',
        'answer' => 'Avery Stone',
    ]);

    $component = livewire(Billing::class);
    $method = new ReflectionMethod(Billing::class, 'receiptSchema');
    $method->setAccessible(true);
    $schema = Schema::make($component->instance())
        ->components($method->invoke($component->instance(), $order));
    $components = collect($schema->getFlatComponents(withHidden: true));

    expect($components->contains(fn ($component): bool => $component instanceof \Filament\Schemas\Components\Section
        && $component->getHeading() === 'Answers for Competition Shirt'))->toBeTrue()
        ->and($components->contains(fn ($component): bool => $component instanceof \Filament\Infolists\Components\TextEntry
            && $component->getLabel() === 'Dancer name'
            && $component->getState() === 'Avery Stone'))->toBeTrue();
});

it('shows credit and gift card information', function () {
    $redeemedGiftCard = GiftCard::factory()
        ->amount(2500)
        ->redeemed(auth()->user())
        ->create();

    CreditGrant::factory()
        ->for(auth()->user())
        ->amount(2500)
        ->create([
            'source_type' => $redeemedGiftCard->getMorphClass(),
            'source_id' => $redeemedGiftCard->id,
            'description' => "Redeemed gift card {$redeemedGiftCard->code}",
        ]);

    $expiresOn = now('America/New_York')->addMonth();

    CreditGrant::factory()
        ->for(auth()->user())
        ->amount(1500)
        ->restrictedTo(ProductType::Course)
        ->create([
            'description' => 'Course scholarship',
            'expires_on' => $expiresOn->toDateString(),
        ]);

    $giftCard = GiftCard::factory()->amount(5000)->create([
        'purchased_by_user_id' => auth()->id(),
    ]);

    $hiddenRedeemedGiftCard = GiftCard::factory()
        ->amount(7500)
        ->redeemed(auth()->user())
        ->create();

    CreditTransaction::factory()->create([
        'user_id' => auth()->id(),
        'description' => 'Hidden billing activity row',
    ]);

    livewire(Billing::class)
        ->assertSee('Remaining')
        ->assertSee('Source')
        ->assertSee('Expiration')
        ->assertSee('$25.00')
        ->assertSee("Redeemed gift card {$redeemedGiftCard->code}")
        ->assertSee('No expiration')
        ->assertSee('$15.00')
        ->assertSee('Description')
        ->assertSee('Course scholarship')
        ->assertSee('Course products only')
        ->assertSee($expiresOn->format('M j, Y'))
        ->assertSee($giftCard->code)
        ->assertSee('$50.00')
        ->assertDontSee($hiddenRedeemedGiftCard->code)
        ->assertDontSee('Credit Details')
        ->assertDontSee('Credit Activity')
        ->assertDontSee('Redeemed Gift Cards')
        ->assertDontSee('Hidden billing activity row');
});

it('renders store and limited use credit as native filament tables', function () {
    $redeemedGiftCard = GiftCard::factory()
        ->amount(2500)
        ->redeemed(auth()->user())
        ->create();

    $storeCredit = CreditGrant::factory()
        ->for(auth()->user())
        ->amount(2500)
        ->create([
            'source_type' => $redeemedGiftCard->getMorphClass(),
            'source_id' => $redeemedGiftCard->id,
            'description' => "Redeemed gift card {$redeemedGiftCard->code}",
        ]);

    $expiresOn = now('America/New_York')->addMonth();
    $limitedUseCredit = CreditGrant::factory()
        ->for(auth()->user())
        ->amount(1500)
        ->restrictedTo(ProductType::Course)
        ->create([
            'description' => 'Course scholarship',
            'expires_on' => $expiresOn->toDateString(),
        ]);

    livewire(BillingCreditGrantsTable::class, ['type' => BillingCreditGrantsTable::TYPE_STORE])
        ->assertTableColumnExists('remaining_amount')
        ->assertTableColumnExists('source_label')
        ->assertTableColumnExists('expires_on')
        ->assertTableColumnDoesNotExist('description')
        ->assertCanSeeTableRecords([$storeCredit])
        ->assertCanNotSeeTableRecords([$limitedUseCredit])
        ->assertSee('$25.00')
        ->assertSee("Redeemed gift card {$redeemedGiftCard->code}")
        ->assertSee('No expiration');

    livewire(BillingCreditGrantsTable::class, ['type' => BillingCreditGrantsTable::TYPE_LIMITED_USE])
        ->assertTableColumnExists('remaining_amount')
        ->assertTableColumnExists('description')
        ->assertTableColumnExists('restriction')
        ->assertTableColumnExists('expires_on')
        ->assertCanSeeTableRecords([$limitedUseCredit])
        ->assertCanNotSeeTableRecords([$storeCredit])
        ->assertSee('$15.00')
        ->assertSee('Course scholarship')
        ->assertSee('Course products only')
        ->assertSee($expiresOn->format('M j, Y'));
});

it('hides limited use credit sections until the user has a balance', function () {
    livewire(Billing::class)
        ->assertDontSee('Limited Use Credit')
        ->assertDontSee('Credit Details')
        ->assertDontSee('Credit Activity')
        ->assertDontSee('Redeemed Gift Cards')
        ->assertDontSee('View Details');
});

it('shows limited use credit details with an overview shortcut when the user has a balance', function () {
    CreditGrant::factory()
        ->for(auth()->user())
        ->amount(2500)
        ->restrictedTo(ProductType::Course)
        ->create();

    livewire(Billing::class)
        ->assertSee('Limited Use Credit')
        ->assertDontSee('Credit Details')
        ->assertSee('View Details')
        ->assertSee('tab=credits', false)
        ->assertSee('limited-use-credits', false)
        ->assertDontSee('Restricted Credit');
});

it('refreshes the credits tab after redeeming a store gift card', function () {
    $giftCard = GiftCard::factory()->amount(5000)->create([
        'code' => 'STORE-CARD-50',
        'purchased_by_user_id' => auth()->id(),
    ]);

    $storeCreditsTable = livewire(BillingCreditGrantsTable::class, ['type' => BillingCreditGrantsTable::TYPE_STORE])
        ->assertSee('No store credit');

    livewire(Billing::class)
        ->assertSee('STORE-CARD-50')
        ->callAction(
            TestAction::make('redeemGiftCard')->schemaComponent(true, 'content'),
            ['code' => $giftCard->code],
        )
        ->assertNotified('Gift card redeemed!')
        ->assertDispatched(BillingCreditGrantsTable::REFRESH_EVENT)
        ->assertSee('No unredeemed purchased gift cards.')
        ->assertSee('$50.00');

    $storeCreditsTable
        ->dispatch(BillingCreditGrantsTable::REFRESH_EVENT)
        ->assertDontSee('No store credit')
        ->assertSee('$50.00')
        ->assertSee('Redeemed gift card STORE-CARD-50');
});

it('refreshes the credits tab after redeeming a limited use gift card', function () {
    $giftCardType = GiftCardType::factory()
        ->restrictedToProductType(ProductType::Course)
        ->denomination(5000)
        ->create();
    $giftCard = GiftCard::factory()
        ->forType($giftCardType)
        ->amount(5000)
        ->create([
            'code' => 'COURSE-CARD-50',
            'purchased_by_user_id' => auth()->id(),
        ]);

    $limitedUseCreditsTable = livewire(BillingCreditGrantsTable::class, ['type' => BillingCreditGrantsTable::TYPE_LIMITED_USE])
        ->assertSee('No limited use credit');

    livewire(Billing::class)
        ->assertSee('COURSE-CARD-50')
        ->assertDontSee('Limited Use Credit')
        ->callAction(
            TestAction::make('redeemGiftCard')->schemaComponent(true, 'content'),
            ['code' => $giftCard->code],
        )
        ->assertNotified('Gift card redeemed!')
        ->assertDispatched(BillingCreditGrantsTable::REFRESH_EVENT)
        ->assertSee('No unredeemed purchased gift cards.')
        ->assertSee('Limited Use Credit')
        ->assertSee('$50.00');

    $limitedUseCreditsTable
        ->dispatch(BillingCreditGrantsTable::REFRESH_EVENT)
        ->assertDontSee('No limited use credit')
        ->assertSee('$50.00')
        ->assertSee('Redeemed gift card COURSE-CARD-50 (Course products only)')
        ->assertSee('Course products only');
});

it('keeps billing overview cards on one row with conditional spans', function () {
    $cards = billingOverviewCards();

    expect(array_map(fn ($card): ?string => $card->getHeading(), $cards))->toBe([
        'Store Credit',
        'Next Payment',
        'Open Seats',
        'Limited Use Credit',
    ])
        ->and($cards[0]->getColumnSpan('md'))->toBe(4)
        ->and($cards[1]->getColumnSpan('md'))->toBe(4)
        ->and($cards[2]->getColumnSpan('md'))->toBe(4)
        ->and($cards[3]->isVisible())->toBeFalse();

    CreditGrant::factory()
        ->for(auth()->user())
        ->amount(2500)
        ->restrictedTo(ProductType::Course)
        ->create();

    $cards = billingOverviewCards();

    expect($cards[0]->getColumnSpan('md'))->toBe(3)
        ->and($cards[1]->getColumnSpan('md'))->toBe(3)
        ->and($cards[2]->getColumnSpan('md'))->toBe(3)
        ->and($cards[3]->getColumnSpan('md'))->toBe(3)
        ->and($cards[3]->isVisible())->toBeTrue();
});

it('shows the total next payment due across payment plans on the same day', function (): void {
    $nextDueDate = now()->addWeek();
    $laterDueDate = now()->addWeeks(2);

    $firstOrder = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $secondOrder = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $laterOrder = Order::factory()->completed()->create(['user_id' => auth()->id()]);

    $firstPaymentPlan = PaymentPlan::factory()->create(['order_id' => $firstOrder->id]);
    $secondPaymentPlan = PaymentPlan::factory()->create(['order_id' => $secondOrder->id]);
    $laterPaymentPlan = PaymentPlan::factory()->create(['order_id' => $laterOrder->id]);

    Installment::factory()->create([
        'payment_plan_id' => $firstPaymentPlan->id,
        'amount' => 4500,
        'due_date' => $nextDueDate,
    ]);

    Installment::factory()->create([
        'payment_plan_id' => $secondPaymentPlan->id,
        'amount' => 3200,
        'due_date' => $nextDueDate,
    ]);

    Installment::factory()->create([
        'payment_plan_id' => $laterPaymentPlan->id,
        'amount' => 9900,
        'due_date' => $laterDueDate,
    ]);

    $nextPaymentEntry = billingOverviewCards()[1]
        ->getChildSchema()
        ->getComponents()[0];

    expect($nextPaymentEntry->getState())
        ->toBe('$77.00 due '.$nextDueDate->format('M j, Y'));
});

it('hides cancelled order details from billing tabs', function () {
    $completedOrder = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'total' => 5000,
    ]);

    $cancelledOrder = Order::factory()->cancelled()->create([
        'user_id' => auth()->id(),
        'total' => 7000,
    ]);

    $cancelledPlan = PaymentPlan::factory()->create([
        'order_id' => $cancelledOrder->id,
    ]);

    Installment::factory()->create([
        'payment_plan_id' => $cancelledPlan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    CreditTransaction::factory()->refund()->create([
        'user_id' => auth()->id(),
        'reference_type' => $cancelledOrder->getMorphClass(),
        'reference_id' => $cancelledOrder->id,
        'description' => "Reversed credit for cancelled order #{$cancelledOrder->id}",
    ]);

    livewire(Billing::class)
        ->assertSee("Order #{$completedOrder->id}")
        ->assertSee('No upcoming payments')
        ->assertDontSee("Order #{$cancelledOrder->id}")
        ->assertDontSee('Cancelled')
        ->assertDontSee('Reversed credit for cancelled order');
});

function billingOverviewCards(): array
{
    $component = livewire(Billing::class);
    $method = new ReflectionMethod(Billing::class, 'getOverviewSchema');
    $method->setAccessible(true);

    $overviewSchema = $method->invoke($component->instance());
    $schema = Schema::make($component->instance())
        ->components($overviewSchema);
    $grid = $schema->getComponents(withHidden: true)[0];

    return array_slice($grid->getChildSchema()->getComponents(withHidden: true), 0, 4);
}

function paymentPlanTermsVersionForBillingTest(): LegalDocumentVersion
{
    $termsVersion = PaymentPlanTerms::currentVersion()
        ?? PaymentPlanTerms::document()?->publishVersion(
            title: 'Payment Plan Terms & Conditions',
            content: '<p>Test payment plan terms.</p>',
        );

    expect($termsVersion)->not->toBeNull();

    return $termsVersion;
}

it('sets a saved payment method as the account default without rewriting active plans', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $paymentMethod = PaymentMethod::constructFrom([
        'id' => 'pm_new',
        'card' => [
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
        ],
    ]);

    $customer = Customer::constructFrom([
        'id' => 'cus_test_123',
        'invoice_settings' => [
            'default_payment_method' => 'pm_old',
        ],
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn($customer);
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([$paymentMethod]);
    $stripeMock->shouldReceive('setDefaultPaymentMethod')
        ->once()
        ->with('cus_test_123', 'pm_new')
        ->andReturn($customer);

    $this->app->instance(StripeServiceContract::class, $stripeMock);

    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Completed,
    ]);

    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_test_123',
        'stripe_payment_method_id' => 'pm_old',
    ]);

    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    livewire(Billing::class)
        ->call('makeDefaultPaymentMethod', 'pm_new')
        ->assertNotified('Default payment method updated');

    expect($plan->refresh()->stripe_payment_method_id)->toBe('pm_old');
});

it('does not remove payment methods assigned to active payment plans', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $paymentMethod = PaymentMethod::constructFrom([
        'id' => 'pm_used',
        'card' => [
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
        ],
    ]);

    $customer = Customer::constructFrom([
        'id' => 'cus_test_123',
        'invoice_settings' => [
            'default_payment_method' => 'pm_used',
        ],
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn($customer);
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([$paymentMethod], [$paymentMethod], []);
    $stripeMock->shouldReceive('detachPaymentMethod')->never();

    $this->app->instance(StripeServiceContract::class, $stripeMock);

    $order = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Completed,
    ]);

    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_test_123',
        'stripe_payment_method_id' => 'pm_used',
    ]);

    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    livewire(Billing::class)
        ->call('removePaymentMethod', 'pm_used')
        ->assertNotified('Payment method is in use');
});

it('does not remove the account default while a legacy active plan depends on it', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $paymentMethod = billingPaymentMethod();
    $customer = Customer::constructFrom([
        'id' => 'cus_test_123',
        'invoice_settings' => [
            'default_payment_method' => 'pm_new',
        ],
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn($customer);
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([$paymentMethod]);
    $stripeMock->shouldReceive('detachPaymentMethod')->never();
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_payment_method_id' => null,
    ]);
    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    livewire(Billing::class)
        ->call('removePaymentMethod', 'pm_new')
        ->assertNotified('Payment method is in use');
});

it('allows removing a payment method not assigned to an active payment plan', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $paymentMethod = billingPaymentMethod();
    $customer = Customer::constructFrom([
        'id' => 'cus_test_123',
        'invoice_settings' => [
            'default_payment_method' => 'pm_default',
        ],
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn($customer);
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([$paymentMethod], [$paymentMethod], []);
    $stripeMock->shouldReceive('detachPaymentMethod')->once()->with('pm_new');
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_payment_method_id' => 'pm_other',
    ]);

    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    livewire(Billing::class)
        ->call('removePaymentMethod', 'pm_new')
        ->assertNotified('Payment method removed')
        ->assertDontSee('Visa ending in 4242 Exp 12/30');
});

it('shows a printable terms link for payment plans', function () {
    $termsVersion = paymentPlanTermsVersionForBillingTest();

    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'payment_plan_terms_version_id' => $termsVersion->id,
    ]);

    PaymentPlan::factory()->create([
        'order_id' => $order->id,
    ]);

    livewire(Billing::class)
        ->assertSee('All payment plans below have been agreed to under these Terms & Conditions')
        ->assertSee(route('legal-documents.versions.show', $termsVersion), false);
});

it('derives payment plan statuses with problem and terminal precedence', function () {
    $activeOrder = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $activePlan = PaymentPlan::factory()->create(['order_id' => $activeOrder->id]);
    Installment::factory()->create([
        'payment_plan_id' => $activePlan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $paidOrder = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $paidPlan = PaymentPlan::factory()->create(['order_id' => $paidOrder->id]);
    Installment::factory()->paid()->create(['payment_plan_id' => $paidPlan->id]);

    $failedOrder = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $failedPlan = PaymentPlan::factory()->create(['order_id' => $failedOrder->id]);
    Installment::factory()->failed()->create(['payment_plan_id' => $failedPlan->id]);

    $overdueOrder = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $overduePlan = PaymentPlan::factory()->create(['order_id' => $overdueOrder->id]);
    Installment::factory()->failed()->create(['payment_plan_id' => $overduePlan->id]);
    Installment::factory()->overdue()->create(['payment_plan_id' => $overduePlan->id]);

    $refundedOrder = Order::factory()->create([
        'user_id' => auth()->id(),
        'status' => OrderStatus::Refunded,
    ]);
    $refundedPlan = PaymentPlan::factory()->create(['order_id' => $refundedOrder->id]);
    Installment::factory()->overdue()->create(['payment_plan_id' => $refundedPlan->id]);

    expect(PaymentPlanStatus::forPaymentPlan($activePlan))->toBe(PaymentPlanStatus::Active)
        ->and(PaymentPlanStatus::forPaymentPlan($paidPlan))->toBe(PaymentPlanStatus::Paid)
        ->and(PaymentPlanStatus::forPaymentPlan($failedPlan))->toBe(PaymentPlanStatus::PaymentFailed)
        ->and(PaymentPlanStatus::forPaymentPlan($overduePlan))->toBe(PaymentPlanStatus::Overdue)
        ->and(PaymentPlanStatus::forPaymentPlan($refundedPlan))->toBe(PaymentPlanStatus::Refunded)
        ->and(PaymentPlanStatus::PaymentFailed->getLabel())->toBe('Payment Failed')
        ->and(PaymentPlanStatus::PaymentFailed->getColor())->toBe('danger')
        ->and(PaymentPlanStatus::Refunded->getColor())->toBe('gray');
});

it('shows terms on each plan card only when multiple versions are represented', function () {
    $firstVersion = paymentPlanTermsVersionForBillingTest();
    $secondVersion = $firstVersion->document()->firstOrFail()->publishVersion(
        title: 'Updated Payment Plan Terms & Conditions',
        content: '<p>Updated test payment plan terms.</p>',
    );

    $plans = collect([$firstVersion, $secondVersion])->map(function (LegalDocumentVersion $version): PaymentPlan {
        $order = Order::factory()->completed()->create([
            'user_id' => auth()->id(),
            'payment_plan_terms_version_id' => $version->id,
        ]);

        return PaymentPlan::factory()->create(['order_id' => $order->id]);
    });

    $component = livewire(Billing::class)
        ->assertSee("View Terms & Conditions {$firstVersion->versionLabel()}")
        ->assertSee("View Terms & Conditions {$secondVersion->versionLabel()}");

    $method = new ReflectionMethod(Billing::class, 'getPaymentPlansSchema');
    $method->setAccessible(true);
    $schema = Schema::make($component->instance())
        ->components($method->invoke($component->instance()));
    $termsEntry = $schema->getComponent("plan_{$plans->first()->id}_terms", withHidden: true);

    expect(mb_substr_count($component->html(), route('legal-documents.versions.show', $firstVersion)))->toBeGreaterThanOrEqual(2)
        ->and(mb_substr_count($component->html(), route('legal-documents.versions.show', $secondVersion)))->toBeGreaterThanOrEqual(2)
        ->and($termsEntry?->getColor($firstVersion->versionLabel()))->toBe('primary')
        ->and($termsEntry?->getIcon($firstVersion->versionLabel()))->toBe(Heroicon::OutlinedDocumentText);
});

it('allows users to print terms they accepted', function () {
    $termsVersion = paymentPlanTermsVersionForBillingTest();

    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
        'payment_plan_terms_version_id' => $termsVersion->id,
    ]);

    $order->legalDocumentAcceptance()->create([
        'legal_document_version_id' => $termsVersion->id,
        'user_id' => auth()->id(),
        'accepted_at' => now(),
    ]);

    $this->get(route('legal-documents.versions.show', $termsVersion))
        ->assertOk()
        ->assertSee('Print')
        ->assertSee('Payment Plan Terms & Conditions');
});

it('shows the assigned card and payment method actions on active plans', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
    ]);

    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_payment_method_id' => 'pm_new',
    ]);

    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $component = livewire(Billing::class)
        ->assertSee('Visa ending in 4242 Exp 12/30')
        ->assertSee('Change Payment Method')
        ->assertDontSee('Add New Payment Method')
        ->assertDontSee('Manage Payment Method');

    $component
        ->mountAction(TestAction::make("change_plan_payment_method_{$plan->id}")->schemaComponent(true, 'content'))
        ->assertActionDataSet(['stripe_payment_method_id' => 'pm_new']);

    $method = new ReflectionMethod(Billing::class, 'changePaymentMethodAction');
    $method->setAccessible(true);
    $action = $method->invoke($component->instance(), $plan);
    $action->livewire($component->instance());

    $optionsMethod = new ReflectionMethod(Billing::class, 'paymentMethodOptions');
    $optionsMethod->setAccessible(true);

    $addNewPaymentMethodAction = $action->getExtraModalFooterActions()['addNewPaymentMethod'] ?? null;

    expect($addNewPaymentMethodAction?->getLabel())->toBe('Add New Payment Method')
        ->and($addNewPaymentMethodAction?->getModalSubmitAction())->toBeNull()
        ->and($addNewPaymentMethodAction?->shouldCancelAllParentActions())->toBeTrue()
        ->and($optionsMethod->invoke($component->instance()))->toBe([
            'pm_new' => 'Visa ending in 4242 Exp 12/30',
        ]);
});

it('changes the saved payment method for one active plan', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_payment_method_id' => 'pm_old',
    ]);

    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    livewire(Billing::class)
        ->assertSee('Assigned payment method unavailable - choose another card')
        ->callAction(TestAction::make("change_plan_payment_method_{$plan->id}")->schemaComponent(true, 'content'), data: [
            'stripe_payment_method_id' => 'pm_new',
        ])
        ->assertNotified('Payment method updated')
        ->assertSee('Visa ending in 4242 Exp 12/30')
        ->assertDontSee('Assigned payment method unavailable - choose another card');

    expect($plan->refresh()->stripe_payment_method_id)->toBe('pm_new');
});

it('rejects a forged payment method when changing an active plan', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_payment_method_id' => 'pm_old',
    ]);
    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    livewire(Billing::class)
        ->set('paymentMethods', [[
            'id' => 'pm_forged',
            'brand' => 'Visa',
            'last4' => '0000',
            'expires' => '12/2030',
            'is_default' => false,
        ]])
        ->callAction(TestAction::make("change_plan_payment_method_{$plan->id}")->schemaComponent(true, 'content'), data: [
            'stripe_payment_method_id' => 'pm_forged',
        ])
        ->assertNotified('Could not update payment method');

    expect($plan->refresh()->stripe_payment_method_id)->toBe('pm_old');
});

it('opens a new modal with a contextual setup intent for an active payment plan', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $plan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $stripeMock->shouldReceive('createSetupIntent')
        ->once()
        ->withArgs(fn (User $user, array $metadata): bool => $user->is(auth()->user())
            && $metadata === [
                'user_id' => (string) auth()->id(),
                'payment_plan_id' => (string) $plan->id,
            ])
        ->andReturn(SetupIntent::constructFrom([
            'id' => 'seti_pending',
            'client_secret' => 'seti_pending_secret',
        ]));
    $stripeMock->shouldReceive('retrieveSetupIntent')
        ->once()
        ->with('seti_pending')
        ->andReturn(billingSetupIntent([
            'id' => 'seti_pending',
            'metadata' => [
                'user_id' => (string) auth()->id(),
                'payment_plan_id' => (string) $plan->id,
            ],
        ]));
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    $changePaymentMethodAction = TestAction::make("change_plan_payment_method_{$plan->id}")
        ->schemaComponent(true, 'content');

    livewire(Billing::class)
        ->mountAction([
            $changePaymentMethodAction,
            'addNewPaymentMethod',
        ])
        ->assertActionMounted([
            $changePaymentMethodAction,
            'addNewPaymentMethod',
        ])
        ->assertSet('paymentMethodTargetPlanId', $plan->id)
        ->assertSet('setupIntentClientSecret', 'seti_pending_secret')
        ->assertMountedActionModalSee('This new payment method will be assigned to this payment plan after it is saved.')
        ->assertMountedActionModalSee('Save Payment Method')
        ->call('paymentMethodSetupCompleted', 'seti_pending', 'pm_new', false)
        ->assertActionNotMounted()
        ->assertNotified('Payment method assigned');

    expect($plan->refresh()->stripe_payment_method_id)->toBe('pm_new');
});

it('shows the general add payment method form beside saved methods after starting setup', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $stripeMock->shouldReceive('createSetupIntent')
        ->once()
        ->withArgs(fn (User $user, array $metadata): bool => $user->is(auth()->user())
            && $metadata === ['user_id' => (string) auth()->id()])
        ->andReturn(SetupIntent::constructFrom([
            'id' => 'seti_pending',
            'client_secret' => 'seti_pending_secret',
        ]));
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Billing::class)
        ->assertSee('Visa ending in 4242 Exp 12/30')
        ->assertDontSee('Make this my account default payment method')
        ->call('startAddingPaymentMethod')
        ->assertSet('paymentMethodTargetPlanId', null)
        ->assertSee('Make this my account default payment method');
});

it('saves a completed setup intent without changing the default', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $paymentMethod = billingPaymentMethod();
    $customer = billingStripeCustomer();
    $setupIntent = billingSetupIntent();

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn($customer);
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([$paymentMethod]);
    $stripeMock->shouldReceive('retrieveSetupIntent')->once()->with('seti_test_123')->andReturn($setupIntent);
    $stripeMock->shouldReceive('setDefaultPaymentMethod')->never();
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Billing::class)
        ->call('paymentMethodSetupCompleted', 'seti_test_123', 'pm_new', false)
        ->assertNotified('Payment method saved');
});

it('can make a newly saved payment method the account default', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $paymentMethod = billingPaymentMethod();
    $customer = billingStripeCustomer();
    $setupIntent = billingSetupIntent();

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn($customer);
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([$paymentMethod]);
    $stripeMock->shouldReceive('retrieveSetupIntent')->once()->with('seti_test_123')->andReturn($setupIntent);
    $stripeMock->shouldReceive('setDefaultPaymentMethod')->once()->with('cus_test_123', 'pm_new');
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Billing::class)
        ->call('paymentMethodSetupCompleted', 'seti_test_123', 'pm_new', true)
        ->assertNotified('Payment method saved and made default');
});

it('assigns a newly saved payment method to its target payment plan', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_payment_method_id' => 'pm_old',
    ]);
    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $stripeMock->shouldReceive('retrieveSetupIntent')->once()->andReturn(billingSetupIntent([
        'metadata' => [
            'user_id' => (string) auth()->id(),
            'payment_plan_id' => (string) $plan->id,
        ],
    ]));
    $stripeMock->shouldReceive('setDefaultPaymentMethod')->never();
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Billing::class)
        ->assertSee('Assigned payment method unavailable - choose another card')
        ->call('paymentMethodSetupCompleted', 'seti_test_123', 'pm_new', false)
        ->assertNotified('Payment method assigned')
        ->assertSee('Visa ending in 4242 Exp 12/30')
        ->assertDontSee('Assigned payment method unavailable - choose another card');

    expect($plan->refresh()->stripe_payment_method_id)->toBe('pm_new');
});

it('rejects a setup intent targeting another users payment plan', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $otherOrder = Order::factory()->completed()->create(['user_id' => User::factory()]);
    $otherPlan = PaymentPlan::factory()->create([
        'order_id' => $otherOrder->id,
        'stripe_payment_method_id' => 'pm_old',
    ]);
    Installment::factory()->create([
        'payment_plan_id' => $otherPlan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $stripeMock->shouldReceive('retrieveSetupIntent')->once()->andReturn(billingSetupIntent([
        'metadata' => [
            'user_id' => (string) auth()->id(),
            'payment_plan_id' => (string) $otherPlan->id,
        ],
    ]));
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Billing::class)
        ->call('paymentMethodSetupCompleted', 'seti_test_123', 'pm_new', false)
        ->assertNotified('Could not save payment method');

    expect($otherPlan->refresh()->stripe_payment_method_id)->toBe('pm_old');
});

it('rejects a setup intent that belongs to another Stripe customer', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $stripeMock->shouldReceive('retrieveSetupIntent')->once()->andReturn(billingSetupIntent([
        'customer' => 'cus_other',
    ]));
    $stripeMock->shouldReceive('setDefaultPaymentMethod')->never();
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Billing::class)
        ->call('paymentMethodSetupCompleted', 'seti_test_123', 'pm_new', true)
        ->assertNotified('Could not save payment method');
});

it('rejects a forged payment method id for a valid setup intent', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $stripeMock->shouldReceive('retrieveSetupIntent')->once()->andReturn(billingSetupIntent());
    $stripeMock->shouldReceive('setDefaultPaymentMethod')->never();
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    livewire(Billing::class)
        ->call('paymentMethodSetupCompleted', 'seti_test_123', 'pm_forged', true)
        ->assertNotified('Could not save payment method');
});

it('preserves the make default choice after a setup intent redirect', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('retrieveSetupIntent')->once()->with('seti_test_123')->andReturn(billingSetupIntent());
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $stripeMock->shouldReceive('setDefaultPaymentMethod')->once()->with('cus_test_123', 'pm_new');
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    Livewire::withQueryParams([
        'setup_intent' => 'seti_test_123',
        'make_default' => '1',
    ])
        ->test(Billing::class)
        ->assertRedirect(Billing::getUrl(['tab' => 'payment-methods']));
});

it('assigns a redirected setup intent and returns to payment plans', function () {
    auth()->user()->update(['stripe_id' => 'cus_test_123']);
    auth()->user()->refresh();

    $order = Order::factory()->completed()->create(['user_id' => auth()->id()]);
    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_payment_method_id' => 'pm_old',
    ]);
    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $stripeMock = Mockery::mock(StripeServiceContract::class);
    $stripeMock->shouldReceive('retrieveSetupIntent')->once()->with('seti_test_123')->andReturn(billingSetupIntent([
        'metadata' => [
            'user_id' => (string) auth()->id(),
            'payment_plan_id' => (string) $plan->id,
        ],
    ]));
    $stripeMock->shouldReceive('createOrGetCustomer')->andReturn(billingStripeCustomer());
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([billingPaymentMethod()]);
    $this->app->instance(StripeServiceContract::class, $stripeMock);

    Livewire::withQueryParams(['setup_intent' => 'seti_test_123'])
        ->test(Billing::class)
        ->assertRedirect(Billing::getUrl(['tab' => 'payment-plans']));

    expect($plan->refresh()->stripe_payment_method_id)->toBe('pm_new');
});

function billingPaymentMethod(): PaymentMethod
{
    return PaymentMethod::constructFrom([
        'id' => 'pm_new',
        'card' => [
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
        ],
    ]);
}

function billingStripeCustomer(): Customer
{
    return Customer::constructFrom([
        'id' => 'cus_test_123',
        'invoice_settings' => [
            'default_payment_method' => 'pm_old',
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function billingSetupIntent(array $overrides = []): SetupIntent
{
    return SetupIntent::constructFrom([
        'id' => 'seti_test_123',
        'status' => 'succeeded',
        'customer' => 'cus_test_123',
        'payment_method' => 'pm_new',
        'metadata' => [
            'user_id' => (string) auth()->id(),
        ],
        ...$overrides,
    ]);
}
