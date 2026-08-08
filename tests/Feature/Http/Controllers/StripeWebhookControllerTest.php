<?php

declare(strict_types=1);

use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderRefundPaymentStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPlanFrequency;
use App\Http\Controllers\StripeWebhookController;
use App\Models\CartItem;
use App\Models\GiftCard;
use App\Models\GiftCardType;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\OrderRefundPayment;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\Mail\ManagedMail;

beforeEach(function () {
    Mail::fake();
    $this->product = Product::factory()->create(['price' => 5000]);
});

it('handles payment_intent.succeeded webhook for order completion', function () {
    $user = User::factory()->create();
    $giftCardType = GiftCardType::factory()->denomination(5000)->create();
    $giftCardProduct = Product::factory()->forGiftCardType($giftCardType)->create(['price' => 5000]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Processing,
        'subtotal' => 10000,
        'total' => 10000,
        'stripe_payment_intent_id' => 'pi_test_webhook',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $giftCardProduct->id,
        'quantity' => 2,
        'unit_price' => 5000,
        'total_price' => 10000,
    ]);

    CartItem::factory()->create([
        'user_id' => $user->id,
        'product_id' => $giftCardProduct->id,
        'quantity' => 2,
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_webhook',
            'payment_method' => 'pm_test_123',
            'customer' => 'cus_test_123',
            'metadata' => (object) [
                'order_id' => (string) $order->id,
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true))->toBe(['message' => 'Order processed']);

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->cart_items_cleared_at)->not->toBeNull()
        ->and($order->receipt_queued_at)->not->toBeNull()
        ->and(CartItem::query()->where('user_id', $user->id)->count())->toBe(0);

    $giftCards = GiftCard::query()->where('order_id', $order->id)->get();

    expect($giftCards)->toHaveCount(2)
        ->and($giftCards->every(fn (GiftCard $giftCard): bool => $giftCard->delivery_email_queued_at !== null))->toBeTrue();

    Mail::assertQueued(ManagedMail::class, 3);

    foreach ($giftCards as $giftCard) {
        Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'gift-card-delivery'
            && $mail->hasTo($user->email)
            && $mail->usesMailer('transactional')
            && str_contains($mail->getRenderedEmail()->html, $giftCard->code));
    }
});

it('returns 400 for invalid webhook signature', function () {
    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andThrow(new Stripe\Exception\SignatureVerificationException('Invalid signature'));

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'invalid_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(400);
    expect($response->getData(true))->toBe(['error' => 'Invalid signature']);
});

it('handles unrecognized event types gracefully', function () {
    $event = new Stripe\Event;
    $event->type = 'some.unknown.event';
    $event->data = (object) ['object' => (object) []];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true))->toBe(['message' => 'Unhandled event type']);
});

it('handles payment intent failed webhook', function () {
    $user = User::factory()->create();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Processing,
        'stripe_payment_intent_id' => 'pi_test_failed',
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.payment_failed';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_failed',
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect($order->refresh()->status)->toBe(OrderStatus::Failed);
});

it('handles payment_intent.succeeded without order or installment metadata gracefully', function () {
    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_no_meta',
            'metadata' => (object) [],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true))->toBe(['message' => 'No order or installment metadata, skipping']);
});

it('does not process an order from a different payment intent with matching metadata', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Processing,
        'stripe_payment_intent_id' => 'pi_expected',
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_wrong_account_or_environment',
            'payment_method' => 'pm_wrong',
            'customer' => 'cus_wrong',
            'metadata' => (object) [
                'order_id' => (string) $order->id,
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')->once()->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $response = app(StripeWebhookController::class)($request);

    expect($response->getData(true))->toBe(['message' => 'Payment intent does not match order'])
        ->and($order->refresh()->status)->toBe(OrderStatus::Processing)
        ->and($order->paymentPlan()->exists())->toBeFalse();
});

it('creates a payment plan when order has payment plan template', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_test_123']);

    $template = PaymentPlanTemplate::factory()->create([
        'number_of_installments' => 3,
        'frequency' => PaymentPlanFrequency::Monthly,
    ]);

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Processing,
        'subtotal' => 9000,
        'total' => 9000,
        'stripe_payment_intent_id' => 'pi_test_plan',
        'payment_plan_template_id' => $template->id,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $this->product->id,
        'quantity' => 1,
        'unit_price' => 9000,
        'total_price' => 9000,
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_plan',
            'payment_method' => 'pm_test_plan',
            'customer' => 'cus_test_123',
            'metadata' => (object) [
                'order_id' => (string) $order->id,
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);

    // Verify payment plan was created
    $paymentPlan = PaymentPlan::query()->where('order_id', $order->id)->first();
    expect($paymentPlan)->not->toBeNull()
        ->and($paymentPlan->total_amount)->toBe(9000)
        ->and($paymentPlan->number_of_installments)->toBe(3)
        ->and($paymentPlan->stripe_customer_id)->toBe('cus_test_123')
        ->and($paymentPlan->stripe_payment_method_id)->toBe('pm_test_plan');

    // Verify installments were created
    expect($paymentPlan->installments)->toHaveCount(3);

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        return $mail->emailTypeKey === 'order-receipt'
            && str_contains($mail->getRenderedEmail()->html, 'Payment Plan');
    });
});

it('idempotently creates a missing plan for an already completed order', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_test_123']);
    $template = PaymentPlanTemplate::factory()->create();
    $order = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'payment_plan_template_id' => $template->id,
        'stripe_payment_intent_id' => 'pi_test_plan_retry',
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_plan_retry',
            'payment_method' => 'pm_test_plan',
            'customer' => 'cus_test_123',
            'metadata' => (object) [
                'order_id' => (string) $order->id,
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')->once()->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $response = app(StripeWebhookController::class)($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($order->paymentPlan()->exists())->toBeTrue()
        ->and($order->paymentPlan()->first()->installments)->toHaveCount($template->number_of_installments)
        ->and($order->paymentPlan()->first()->stripe_payment_method_id)->toBe('pm_test_plan');
});

it('idempotently repairs missing stripe ids without overwriting a selected plan card', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_test_123']);
    $template = PaymentPlanTemplate::factory()->create();

    $missingMethodOrder = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'payment_plan_template_id' => $template->id,
        'stripe_payment_intent_id' => 'pi_test_plan_retry_missing',
    ]);
    $missingMethodPlan = PaymentPlan::factory()->create([
        'order_id' => $missingMethodOrder->id,
        'stripe_customer_id' => null,
        'stripe_payment_method_id' => null,
    ]);

    $selectedMethodOrder = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'payment_plan_template_id' => $template->id,
        'stripe_payment_intent_id' => 'pi_test_plan_retry_selected',
    ]);
    $selectedMethodPlan = PaymentPlan::factory()->create([
        'order_id' => $selectedMethodOrder->id,
        'stripe_payment_method_id' => 'pm_user_selected',
    ]);

    foreach ([
        [$missingMethodOrder, 'pi_test_plan_retry_missing'],
        [$selectedMethodOrder, 'pi_test_plan_retry_selected'],
    ] as [$order, $paymentIntentId]) {
        $event = new Stripe\Event;
        $event->type = 'payment_intent.succeeded';
        $event->data = (object) [
            'object' => (object) [
                'id' => $paymentIntentId,
                'payment_method' => 'pm_checkout',
                'customer' => 'cus_test_123',
                'metadata' => (object) [
                    'order_id' => (string) $order->id,
                ],
            ],
        ];

        $mockStripeService = Mockery::mock(StripeServiceContract::class);
        $mockStripeService->shouldReceive('constructWebhookEvent')->once()->andReturn($event);
        $this->app->instance(StripeServiceContract::class, $mockStripeService);

        $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'test_signature',
        ]);

        expect(app(StripeWebhookController::class)($request)->getStatusCode())->toBe(200);
    }

    expect($missingMethodPlan->refresh()->stripe_customer_id)->toBe('cus_test_123')
        ->and($missingMethodPlan->stripe_payment_method_id)->toBe('pm_checkout')
        ->and($selectedMethodPlan->refresh()->stripe_payment_method_id)->toBe('pm_user_selected');
});

it('handles payment_intent.succeeded webhook for installment', function () {
    $plan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_inst_success',
            'metadata' => (object) [
                'installment_id' => (string) $installment->id,
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect($installment->refresh()->status)->toBe(InstallmentStatus::Paid);
    expect($installment->stripe_payment_intent_id)->toBe('pi_test_inst_success');

    Mail::assertQueued(ManagedMail::class, fn (ManagedMail $mail): bool => $mail->emailTypeKey === 'payment-plan-installment-succeeded'
        && $mail->hasTo($plan->order->user->email)
        && $mail->usesMailer('transactional'));
});

it('handles payment_intent.payment_failed webhook for an installment', function () {
    $plan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'status' => InstallmentStatus::Pending,
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.payment_failed';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_test_inst_failed',
            'customer' => 'cus_test_123',
            'payment_method' => 'pm_test_123',
            'metadata' => (object) [
                'installment_id' => (string) $installment->id,
                'order_id' => (string) $plan->order_id,
            ],
            'last_payment_error' => (object) [
                'message' => 'Your card was declined.',
                'code' => 'card_declined',
                'decline_code' => 'insufficient_funds',
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')->once()->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $response = app(StripeWebhookController::class)($request);

    expect($response->getData(true))->toBe(['message' => 'Installment payment failure processed'])
        ->and($installment->refresh()->status)->toBe(InstallmentStatus::Failed)
        ->and($installment->retry_count)->toBe(1);

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'payment-plan-installment-failed'
            && str_contains($rendered->html, 'Your card was declined.')
            && str_contains($rendered->html, 'insufficient_funds');
    });
});

it('prioritizes installment metadata and does not duplicate an already processed success email', function () {
    $plan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'stripe_payment_intent_id' => 'pi_already_processed',
    ]);

    $event = new Stripe\Event;
    $event->type = 'payment_intent.succeeded';
    $event->data = (object) [
        'object' => (object) [
            'id' => 'pi_already_processed',
            'metadata' => (object) [
                'installment_id' => (string) $installment->id,
                'order_id' => (string) $plan->order_id,
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')->once()->andReturn($event);
    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    expect(app(StripeWebhookController::class)($request)->getData(true))
        ->toBe(['message' => 'Installment payment processed']);

    Mail::assertNothingQueued();
});

it('ignores invoice webhooks for payment plans', function (string $eventType) {
    $event = new Stripe\Event;
    $event->type = $eventType;
    $event->data = (object) [
        'object' => (object) [
            'id' => 'inv_test',
            'metadata' => (object) [
                'installment_id' => '123',
            ],
        ],
    ];

    $mockStripeService = Mockery::mock(StripeServiceContract::class);
    $mockStripeService->shouldReceive('constructWebhookEvent')
        ->once()
        ->andReturn($event);

    $this->app->instance(StripeServiceContract::class, $mockStripeService);

    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    $controller = app(StripeWebhookController::class);
    $response = $controller($request);

    expect($response->getStatusCode())->toBe(200);
    expect(json_decode((string) $response->getContent(), true))->toBe([
        'message' => 'Unhandled event type',
    ]);
})->with([
    'paid invoice' => 'invoice.paid',
    'failed invoice' => 'invoice.payment_failed',
]);

it('reconciles successful refund updates idempotently', function (): void {
    $order = Order::factory()->completed()->create([
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_refund_webhook',
    ]);
    $refund = OrderRefund::factory()->create([
        'order_id' => $order->id,
        'amount' => 5000,
        'status' => OrderRefundStatus::Pending,
    ]);
    $payment = OrderRefundPayment::factory()->create([
        'order_refund_id' => $refund->id,
        'stripe_payment_intent_id' => 'pi_refund_webhook',
        'stripe_refund_id' => 're_webhook',
        'amount' => 5000,
        'status' => OrderRefundPaymentStatus::Pending,
    ]);

    $event = new Stripe\Event;
    $event->type = 'refund.updated';
    $event->data = (object) [
        'object' => (object) [
            'id' => 're_webhook',
            'status' => 'succeeded',
            'failure_reason' => null,
            'metadata' => (object) [
                'order_refund_payment_id' => (string) $payment->id,
            ],
        ],
    ];
    $staleEvent = clone $event;
    $staleEvent->type = 'refund.created';
    $staleEvent->data = (object) [
        'object' => (object) [
            'id' => 're_webhook',
            'status' => 'pending',
            'failure_reason' => null,
            'metadata' => (object) [
                'order_refund_payment_id' => (string) $payment->id,
            ],
        ],
    ];

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('constructWebhookEvent')->twice()->andReturn($event, $staleEvent);
    $this->app->instance(StripeServiceContract::class, $stripe);
    $request = Request::create('/stripe/webhook', 'POST', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_signature',
    ]);

    expect(app(StripeWebhookController::class)($request)->getData(true))
        ->toBe(['message' => 'Refund status processed'])
        ->and(app(StripeWebhookController::class)($request)->getData(true))
        ->toBe(['message' => 'Refund status processed'])
        ->and($payment->refresh()->status)->toBe(OrderRefundPaymentStatus::Succeeded)
        ->and($refund->refresh()->status)->toBe(OrderRefundStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});
