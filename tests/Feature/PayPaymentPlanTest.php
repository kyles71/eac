<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Filament\User\Pages\Billing;
use App\Filament\User\Pages\PayPaymentPlan;
use App\Filament\User\Widgets\NeedsAttention;
use App\Models\Installment;
use App\Models\InstallmentPaymentAttempt;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('user'));
});

it('shows the dedicated pay now page to the payment plan customer', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 3500,
    ]);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertOk()
        ->assertSee("Payment plan for Order #{$order->id}")
        ->assertSee('$35.00')
        ->assertActionVisible('preparePayment')
        ->mountAction('preparePayment')
        ->assertSchemaComponentExists('installment_ids')
        ->assertSchemaComponentDoesNotExist('use_for_future');

    livewire(Billing::class)
        ->assertSee('Pay Now')
        ->assertSee(PayPaymentPlan::getUrl(['paymentPlan' => $paymentPlan]));

    $attentionTask = collect((new NeedsAttention)->tasks())
        ->firstWhere('action', 'Pay now');

    expect($attentionTask['url'] ?? null)->toBe(PayPaymentPlan::getUrl([
        'paymentPlan' => $paymentPlan,
    ]));
});

it('does not expose another customers payment plan page', function (): void {
    $owner = User::factory()->create();
    $order = Order::factory()->completed()->create(['user_id' => $owner->id]);
    $paymentPlan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    Installment::factory()->overdue()->create(['payment_plan_id' => $paymentPlan->id]);

    $this->get(PayPaymentPlan::getUrl(['paymentPlan' => $paymentPlan]))
        ->assertForbidden();
});

it('shows Pay Now after the latest payment attempt was cancelled', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 3500,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'initiated_by_user_id' => $customer->id,
        'origin' => InstallmentPaymentAttemptOrigin::Customer,
        'status' => InstallmentPaymentAttemptStatus::Cancelled,
        'total_amount' => $installment->amount,
        'completed_at' => now(),
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertOk()
        ->assertActionVisible('preparePayment')
        ->assertSee('Pay Now');
});

it('offers recovery instead of loading Stripe for an interrupted payment setup', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 3500,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'initiated_by_user_id' => $customer->id,
        'origin' => InstallmentPaymentAttemptOrigin::Customer,
        'status' => InstallmentPaymentAttemptStatus::Pending,
        'total_amount' => $installment->amount,
        'stripe_payment_intent_id' => null,
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertOk()
        ->assertSee('Payment setup was interrupted')
        ->assertSee('Retry payment setup')
        ->assertDontSee('https://js.stripe.com/v3/');
});

it('renders the Stripe payment form with a script-backed Alpine component', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $customer->update(['stripe_id' => 'cus_customer']);
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_customer',
    ]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 3500,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'initiated_by_user_id' => $customer->id,
        'origin' => InstallmentPaymentAttemptOrigin::Customer,
        'status' => InstallmentPaymentAttemptStatus::RequiresPaymentMethod,
        'total_amount' => $installment->amount,
        'stripe_payment_intent_id' => 'pi_payment_form',
        'stripe_customer_id' => 'cus_customer',
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('retrievePaymentIntent')
        ->once()
        ->with('pi_payment_form')
        ->andReturn(Stripe\PaymentIntent::constructFrom([
            'id' => 'pi_payment_form',
            'client_secret' => 'pi_payment_form_secret_test',
        ]));
    $stripe->shouldReceive('createCustomerSession')
        ->once()
        ->with('cus_customer', false)
        ->andReturn(Stripe\CustomerSession::constructFrom([
            'client_secret' => 'cuss_payment_form_secret_test',
        ]));
    $this->app->instance(StripeServiceContract::class, $stripe);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertOk()
        ->assertSee('x-data="paymentPlanPayment"', escape: false)
        ->assertSee('data-client-secret="pi_payment_form_secret_test"', escape: false)
        ->assertSee('Use the selected payment method for future installments on this plan')
        ->assertSee('data-use-for-future="false"', escape: false)
        ->assertDontSee('x-data="{\n    stripe:', escape: false);
});

it('configures the active payment intent for future installments before confirmation', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $customer->update(['stripe_id' => 'cus_customer']);
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_customer',
    ]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 3500,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'initiated_by_user_id' => $customer->id,
        'origin' => InstallmentPaymentAttemptOrigin::Customer,
        'status' => InstallmentPaymentAttemptStatus::RequiresPaymentMethod,
        'total_amount' => $installment->amount,
        'stripe_payment_intent_id' => 'pi_future_payment',
        'stripe_customer_id' => 'cus_customer',
        'use_for_future' => false,
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('retrievePaymentIntent')
        ->once()
        ->with('pi_future_payment')
        ->andReturn(Stripe\PaymentIntent::constructFrom([
            'id' => 'pi_future_payment',
            'client_secret' => 'pi_future_payment_secret_test',
        ]));
    $stripe->shouldReceive('createCustomerSession')
        ->once()
        ->with('cus_customer', false)
        ->andReturn(Stripe\CustomerSession::constructFrom([
            'client_secret' => 'cuss_future_payment_secret_test',
        ]));
    $stripe->shouldReceive('updatePaymentIntentSetupFutureUsage')
        ->once()
        ->with('pi_future_payment', true)
        ->andReturn(Stripe\PaymentIntent::constructFrom(['id' => 'pi_future_payment']));
    $this->app->instance(StripeServiceContract::class, $stripe);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->call('configureFuturePayments', true)
        ->assertReturned(['successful' => true, 'message' => null]);

    expect($paymentAttempt->refresh()->use_for_future)->toBeTrue();
});
