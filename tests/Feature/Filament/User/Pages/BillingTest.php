<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Filament\User\Pages\Billing;
use App\Models\CreditTransaction;
use App\Models\GiftCard;
use App\Models\Installment;
use App\Models\LegalDocumentVersion;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\RestrictedCredit;
use App\Models\User;
use App\Support\LegalDocuments\PaymentPlanTerms;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Stripe\Customer;
use Stripe\PaymentMethod;

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

it('shows credit and gift card information', function () {
    auth()->user()->update(['credit_balance' => 2500]);

    $giftCard = GiftCard::factory()->amount(5000)->create([
        'purchased_by_user_id' => auth()->id(),
    ]);

    livewire(Billing::class)
        ->assertSee('$25.00')
        ->assertSee($giftCard->code)
        ->assertSee('$50.00');
});

it('hides limited use credit sections until the user has a balance', function () {
    livewire(Billing::class)
        ->assertDontSee('Limited Use Credit')
        ->assertDontSee('Limited Use Credit Details')
        ->assertDontSee('View Details');
});

it('shows limited use credit details with an overview shortcut when the user has a balance', function () {
    RestrictedCredit::factory()->balance(2500)->create([
        'user_id' => auth()->id(),
    ]);

    livewire(Billing::class)
        ->assertSee('Limited Use Credit')
        ->assertSee('Limited Use Credit Details')
        ->assertSee('View Details')
        ->assertSee('tab=credits', false)
        ->assertSee('limited-use-credit-details', false)
        ->assertDontSee('Restricted Credit');
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

    RestrictedCredit::factory()->balance(2500)->create([
        'user_id' => auth()->id(),
    ]);

    $cards = billingOverviewCards();

    expect($cards[0]->getColumnSpan('md'))->toBe(3)
        ->and($cards[1]->getColumnSpan('md'))->toBe(3)
        ->and($cards[2]->getColumnSpan('md'))->toBe(3)
        ->and($cards[3]->getColumnSpan('md'))->toBe(3)
        ->and($cards[3]->isVisible())->toBeTrue();
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

it('sets a saved payment method as default and updates active auto charge plans', function () {
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

    expect($plan->refresh()->stripe_payment_method_id)->toBe('pm_new');
});

it('does not remove payment methods used by active auto charge plans', function () {
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
    $stripeMock->shouldReceive('listPaymentMethods')->andReturn([$paymentMethod]);
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

it('updates a payment plan saved payment method without method choice', function () {
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

    $this->app->instance(StripeServiceContract::class, $stripeMock);

    $order = Order::factory()->completed()->create([
        'user_id' => auth()->id(),
    ]);

    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_payment_method_id' => 'pm_old',
    ]);

    Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    livewire(Billing::class)
        ->callAction(TestAction::make("update_plan_payment_method_{$plan->id}")->schemaComponent(true, 'content'), data: [
            'stripe_payment_method_id' => 'pm_new',
        ])
        ->assertNotified('Payment method updated')
        ->assertDontSee('Payment Plan Method');

    expect($plan->refresh()->stripe_payment_method_id)->toBe('pm_new');
});
