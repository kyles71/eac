<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentPaymentAttemptOrigin;
use App\Enums\InstallmentPaymentAttemptStatus;
use App\Filament\User\Pages\Billing;
use App\Filament\User\Pages\PayPaymentPlan;
use App\Models\Installment;
use App\Models\InstallmentPaymentAttempt;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Support\UserAttention;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

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

    $attentionTask = collect(app(UserAttention::class)->tasks($customer))
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

it('does not show a historical customer payment as a new confirmation', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create(['order_id' => $order->id]);
    $paidInstallment = Installment::factory()->paid()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 1,
        'amount' => 1666,
    ]);
    $remainingInstallment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 1666,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'initiated_by_user_id' => $customer->id,
        'origin' => InstallmentPaymentAttemptOrigin::Customer,
        'status' => InstallmentPaymentAttemptStatus::Succeeded,
        'total_amount' => $paidInstallment->amount,
        'completed_at' => now(),
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $paidInstallment->id,
        'amount' => $paidInstallment->amount,
    ]);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertOk()
        ->assertDontSee('Payment successful')
        ->assertSee('1', escape: false)
        ->assertSee("mountAction('preparePayment'", escape: false)
        ->assertActionVisible('preparePayment')
        ->mountAction('preparePayment')
        ->assertActionDataSet([
            'installment_ids' => [$remainingInstallment->id],
        ]);
});

it('does not show a scheduled installment payment as a catch-up confirmation', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'total_amount' => 29764,
    ]);
    Installment::factory()->paid()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 1,
        'amount' => 7441,
    ]);
    Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 7441,
    ]);
    Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 3,
        'amount' => 7441,
    ]);
    $scheduledInstallment = Installment::factory()->paid()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 4,
        'amount' => 7441,
    ]);
    $scheduledAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'origin' => InstallmentPaymentAttemptOrigin::Scheduled,
        'status' => InstallmentPaymentAttemptStatus::Succeeded,
        'total_amount' => $scheduledInstallment->amount,
        'completed_at' => now(),
    ]);
    $scheduledAttempt->allocations()->create([
        'installment_id' => $scheduledInstallment->id,
        'amount' => $scheduledInstallment->amount,
    ]);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertOk()
        ->assertSee('Missed balance')
        ->assertSee('$148.82')
        ->assertSee('Missed installments')
        ->assertDontSee('Payment successful')
        ->assertActionVisible('preparePayment');
});

it('shows a neutral message instead of loading an active scheduled payment', function (): void {
    /** @var User $customer */
    $customer = auth()->user();
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'total_amount' => 3500,
    ]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 3500,
    ]);
    $scheduledAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'origin' => InstallmentPaymentAttemptOrigin::Scheduled,
        'status' => InstallmentPaymentAttemptStatus::Processing,
        'total_amount' => $installment->amount,
        'stripe_payment_intent_id' => 'pi_scheduled_processing',
    ]);
    $scheduledAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldNotReceive('retrievePaymentIntent');
    $stripe->shouldNotReceive('createCustomerSession');
    $this->app->instance(StripeServiceContract::class, $stripe);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertOk()
        ->assertSee('$35.00')
        ->assertSee('A payment is already in progress for the missed installments.')
        ->assertDontSee('Review and pay')
        ->assertDontSee('x-data="paymentPlanPayment"', escape: false);
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
        'total_amount' => 14882,
    ]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 7441,
        'due_date' => '2026-08-18',
    ]);
    $secondInstallment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 3,
        'amount' => 7441,
        'due_date' => '2026-08-25',
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'initiated_by_user_id' => $customer->id,
        'origin' => InstallmentPaymentAttemptOrigin::Customer,
        'status' => InstallmentPaymentAttemptStatus::RequiresPaymentMethod,
        'total_amount' => $installment->amount + $secondInstallment->amount,
        'stripe_payment_intent_id' => 'pi_payment_form',
        'stripe_customer_id' => 'cus_customer',
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $secondInstallment->id,
        'amount' => $secondInstallment->amount,
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
        ->assertSee('Missed balance')
        ->assertSee('$148.82')
        ->assertDontSee('$0.00')
        ->assertSee('Review and pay')
        ->assertSee('Selected installments')
        ->assertSee('#2 — due Aug 18, 2026')
        ->assertSee('#3 — due Aug 25, 2026')
        ->assertSee('Amount due now')
        ->assertDontSee('Needs payment method')
        ->assertSee('Use the selected payment method for future installments on this plan')
        ->assertSee('data-use-for-future="false"', escape: false)
        ->assertDontSee('x-data="{\n    stripe:', escape: false);
});

it('shows a customer payment confirmation once and offers the next missed installment', function (): void {
    Queue::fake();

    /** @var User $customer */
    $customer = auth()->user();
    $customer->update(['stripe_id' => 'cus_customer']);
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_customer',
        'total_amount' => 5500,
    ]);
    $paidInstallment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 3500,
    ]);
    $remainingInstallment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 3,
        'amount' => 2000,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'initiated_by_user_id' => $customer->id,
        'origin' => InstallmentPaymentAttemptOrigin::Customer,
        'status' => InstallmentPaymentAttemptStatus::RequiresPaymentMethod,
        'total_amount' => $paidInstallment->amount,
        'stripe_payment_intent_id' => 'pi_customer_completion',
        'stripe_customer_id' => 'cus_customer',
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $paidInstallment->id,
        'amount' => $paidInstallment->amount,
    ]);

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('retrievePaymentIntent')
        ->twice()
        ->with('pi_customer_completion')
        ->andReturn(
            Stripe\PaymentIntent::constructFrom([
                'id' => 'pi_customer_completion',
                'client_secret' => 'pi_customer_completion_secret_test',
                'status' => 'requires_payment_method',
            ]),
            Stripe\PaymentIntent::constructFrom([
                'id' => 'pi_customer_completion',
                'status' => 'succeeded',
                'amount' => $paidInstallment->amount,
                'currency' => 'usd',
                'customer' => 'cus_customer',
                'payment_method' => 'pm_customer_completion',
                'metadata' => [
                    'payment_attempt_id' => (string) $paymentAttempt->id,
                ],
            ]),
        );
    $stripe->shouldReceive('createCustomerSession')
        ->once()
        ->with('cus_customer', false)
        ->andReturn(Stripe\CustomerSession::constructFrom([
            'client_secret' => 'cuss_customer_completion_secret_test',
        ]));
    $this->app->instance(StripeServiceContract::class, $stripe);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertDontSee('Payment successful')
        ->call('completePayment', 'pi_customer_completion')
        ->assertReturned(['status' => 'Succeeded', 'message' => null]);

    expect($paidInstallment->refresh()->status->value)->toBe('Paid');

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertSee('Payment successful')
        ->assertSee('$35.00 was applied to the selected installments.')
        ->assertSee('Pay another missed installment')
        ->call('payAnotherMissedInstallment')
        ->assertActionMounted('preparePayment')
        ->assertActionDataSet([
            'installment_ids' => [$remainingInstallment->id],
        ]);

    livewire(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertDontSee('Payment successful')
        ->assertActionVisible('preparePayment');
});

it('shows a redirected customer payment confirmation only once', function (): void {
    Queue::fake();

    /** @var User $customer */
    $customer = auth()->user();
    $order = Order::factory()->completed()->create(['user_id' => $customer->id]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'total_amount' => 3500,
    ]);
    $installment = Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'amount' => 3500,
    ]);
    $paymentAttempt = InstallmentPaymentAttempt::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'initiated_by_user_id' => $customer->id,
        'origin' => InstallmentPaymentAttemptOrigin::Customer,
        'status' => InstallmentPaymentAttemptStatus::RequiresAction,
        'total_amount' => $installment->amount,
        'stripe_payment_intent_id' => 'pi_redirected_completion',
        'stripe_customer_id' => 'cus_redirected_completion',
    ]);
    $paymentAttempt->allocations()->create([
        'installment_id' => $installment->id,
        'amount' => $installment->amount,
    ]);
    $succeededPaymentIntent = Stripe\PaymentIntent::constructFrom([
        'id' => 'pi_redirected_completion',
        'status' => 'succeeded',
        'amount' => $installment->amount,
        'currency' => 'usd',
        'customer' => 'cus_redirected_completion',
        'payment_method' => 'pm_redirected_completion',
        'metadata' => [
            'payment_attempt_id' => (string) $paymentAttempt->id,
        ],
    ]);

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('retrievePaymentIntent')
        ->twice()
        ->with('pi_redirected_completion')
        ->andReturn($succeededPaymentIntent, $succeededPaymentIntent);
    $this->app->instance(StripeServiceContract::class, $stripe);

    Livewire::withQueryParams(['payment_intent' => 'pi_redirected_completion'])
        ->test(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertSee('Payment successful')
        ->assertSee('$35.00 was applied to the selected installments.');

    Livewire::withQueryParams(['payment_intent' => 'pi_redirected_completion'])
        ->test(PayPaymentPlan::class, ['paymentPlan' => $paymentPlan])
        ->assertDontSee('Payment successful')
        ->assertSee('There are no failed or overdue installments to pay.');
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
