<?php

declare(strict_types=1);

use App\Actions\Store\IssueOrderRefundAction;
use App\Contracts\StripeServiceContract;
use App\Enums\InstallmentStatus;
use App\Enums\OrderRefundPaymentStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Models\Course;
use App\Models\CreditGrant;
use App\Models\CreditTransaction;
use App\Models\Enrollment;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefundPayment;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Str;
use Stripe\Refund;

it('uses a persisted UUID for refund payment idempotency', function (): void {
    $firstPayment = OrderRefundPayment::factory()->create();
    $secondPayment = OrderRefundPayment::factory()->create();

    expect(Str::isUuid($firstPayment->idempotency_key))->toBeTrue()
        ->and($firstPayment->idempotency_key)->not->toBe($secondPayment->idempotency_key)
        ->and($firstPayment->idempotencyKey())->toBe("order-refund-payment-{$firstPayment->idempotency_key}")
        ->and($firstPayment->refresh()->idempotencyKey())->toBe($firstPayment->idempotencyKey())
        ->and($firstPayment->idempotencyKey())->not->toBe("order-refund-payment-{$firstPayment->id}");
});

it('issues and records a partial Stripe refund', function (): void {
    $order = Order::factory()->completed()->create([
        'subtotal' => 10000,
        'total' => 10000,
        'stripe_payment_intent_id' => 'pi_checkout',
    ]);
    $admin = User::factory()->create();
    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('refundPaymentIntent')
        ->once()
        ->withArgs(fn (string $paymentIntentId, int $amount, array $metadata, string $idempotencyKey): bool => $paymentIntentId === 'pi_checkout'
            && $amount === 2500
            && $metadata['order_id'] === (string) $order->id
            && str_starts_with($idempotencyKey, 'order-refund-payment-'))
        ->andReturn(stripeRefundForOrderTest('re_partial'));
    $this->app->instance(StripeServiceContract::class, $stripe);

    $refund = app(IssueOrderRefundAction::class)->handle(
        order: $order,
        processedBy: $admin,
        amount: 2500,
        reason: 'Customer withdrew before classes began.',
    );

    expect($refund->status)->toBe(OrderRefundStatus::Succeeded)
        ->and($refund->reason)->toBe('Customer withdrew before classes began.')
        ->and($refund->payments)->toHaveCount(1)
        ->and($refund->payments->first()->status)->toBe(OrderRefundPaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($order->refundableAmount())->toBe(7500);
});

it('rejects refunds above the remaining captured balance', function (): void {
    $order = Order::factory()->completed()->create([
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_checkout',
    ]);
    $this->app->instance(StripeServiceContract::class, Mockery::mock(StripeServiceContract::class));

    app(IssueOrderRefundAction::class)->handle(
        order: $order,
        processedBy: User::factory()->create(),
        amount: 5001,
        reason: 'Too much.',
    );
})->throws(InvalidArgumentException::class, 'exceeds the remaining refundable balance');

it('removes only selected order enrollments after a successful refund', function (): void {
    $course = Course::factory()->create();
    $product = Product::factory()->forCourse($course)->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_checkout',
    ]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
    ]);
    $student = Student::factory()->create(['user_id' => $order->user_id]);
    $selectedEnrollment = Enrollment::factory()->create([
        'order_item_id' => $orderItem->id,
        'course_id' => $course->id,
        'user_id' => $order->user_id,
        'student_id' => $student->id,
    ]);
    $secondSelectedEnrollment = Enrollment::factory()->create([
        'order_item_id' => $orderItem->id,
        'course_id' => $course->id,
        'user_id' => $order->user_id,
    ]);
    $preservedEnrollment = Enrollment::factory()->create([
        'order_item_id' => $orderItem->id,
        'course_id' => $course->id,
        'user_id' => $order->user_id,
    ]);
    bindSuccessfulOrderRefundStripe('re_enrollment');

    $refund = app(IssueOrderRefundAction::class)->handle(
        order: $order,
        processedBy: User::factory()->create(),
        amount: 5000,
        reason: 'Enrollment cancellation.',
        enrollmentIds: [$selectedEnrollment->id, $secondSelectedEnrollment->id],
    );

    expect(Enrollment::query()->find($selectedEnrollment->id))->toBeNull()
        ->and(Enrollment::query()->find($secondSelectedEnrollment->id))->toBeNull()
        ->and(Enrollment::query()->find($preservedEnrollment->id))->not->toBeNull()
        ->and($refund->enrollment_details)->toBe([
            [
                'id' => $selectedEnrollment->id,
                'student' => $student->fullName,
                'course' => $course->name,
            ],
            [
                'id' => $secondSelectedEnrollment->id,
                'student' => 'Unassigned seat',
                'course' => $course->name,
            ],
        ])
        ->and($refund->additionalActionDescriptions())->toContain(
            "Removed enrollment: {$student->fullName} — {$course->name} (Enrollment #{$selectedEnrollment->id})",
            "Removed enrollment: Unassigned seat — {$course->name} (Enrollment #{$secondSelectedEnrollment->id})",
        )
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('allocates a payment plan refund newest first and optionally cancels future installments', function (): void {
    $template = PaymentPlanTemplate::factory()->create(['number_of_installments' => 3]);
    $order = Order::factory()->completed()->create([
        'subtotal' => 9000,
        'total' => 9000,
        'payment_plan_principal' => 9000,
        'payment_plan_template_id' => $template->id,
        'stripe_payment_intent_id' => 'pi_checkout',
    ]);
    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'payment_plan_template_id' => $template->id,
        'total_amount' => 9000,
        'number_of_installments' => 3,
    ]);
    Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'installment_number' => 1,
        'amount' => 3000,
        'stripe_payment_intent_id' => null,
    ]);
    Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'installment_number' => 2,
        'amount' => 3000,
        'stripe_payment_intent_id' => 'pi_later',
        'paid_at' => now(),
    ]);
    $future = Installment::factory()->create([
        'payment_plan_id' => $plan->id,
        'installment_number' => 3,
        'amount' => 3000,
    ]);

    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('refundPaymentIntent')
        ->once()
        ->ordered()
        ->withArgs(fn (string $paymentIntentId, int $amount): bool => $paymentIntentId === 'pi_later' && $amount === 3000)
        ->andReturn(stripeRefundForOrderTest('re_later'));
    $stripe->shouldReceive('refundPaymentIntent')
        ->once()
        ->ordered()
        ->withArgs(fn (string $paymentIntentId, int $amount): bool => $paymentIntentId === 'pi_checkout' && $amount === 3000)
        ->andReturn(stripeRefundForOrderTest('re_checkout'));
    $this->app->instance(StripeServiceContract::class, $stripe);

    $refund = app(IssueOrderRefundAction::class)->handle(
        order: $order,
        processedBy: User::factory()->create(),
        amount: 6000,
        reason: 'Cancel payment plan and refund all collected funds.',
        cancelRemainingInstallments: true,
    );

    expect($refund->payments)->toHaveCount(2)
        ->and($refund->payments->pluck('failure_reason', 'stripe_payment_intent_id')->all())->toBe([
            'pi_later' => null,
            'pi_checkout' => null,
        ])
        ->and($refund->status)->toBe(OrderRefundStatus::Succeeded)
        ->and($refund->cancel_remaining_installments)->toBeTrue()
        ->and($refund->installments_cancelled_at)->not->toBeNull()
        ->and($future->refresh()->status)->toBe(InstallmentStatus::Cancelled)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('shows refund status for fully and partially refunded installments', function (): void {
    $order = Order::factory()->completed()->create([
        'subtotal' => 6000,
        'total' => 6000,
        'stripe_payment_intent_id' => 'pi_first_installment',
    ]);
    $plan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'total_amount' => 6000,
        'number_of_installments' => 2,
    ]);
    $firstInstallment = Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'installment_number' => 1,
        'amount' => 3000,
        'stripe_payment_intent_id' => null,
    ]);
    $secondInstallment = Installment::factory()->paid()->create([
        'payment_plan_id' => $plan->id,
        'installment_number' => 2,
        'amount' => 3000,
        'stripe_payment_intent_id' => 'pi_second_installment',
    ]);
    $refund = App\Models\OrderRefund::factory()->create([
        'order_id' => $order->id,
        'amount' => 4000,
        'status' => OrderRefundStatus::Succeeded,
        'completed_at' => now(),
    ]);
    OrderRefundPayment::factory()->create([
        'order_refund_id' => $refund->id,
        'stripe_payment_intent_id' => 'pi_first_installment',
        'amount' => 1000,
        'status' => OrderRefundPaymentStatus::Succeeded,
    ]);
    OrderRefundPayment::factory()->create([
        'order_refund_id' => $refund->id,
        'stripe_payment_intent_id' => 'pi_second_installment',
        'amount' => 3000,
        'status' => OrderRefundPaymentStatus::Succeeded,
    ]);

    expect($firstInstallment->paymentStatusLabel())->toBe('Partial Refund')
        ->and($secondInstallment->paymentStatusLabel())->toBe('Refund')
        ->and($firstInstallment->status)->toBe(InstallmentStatus::Paid)
        ->and($secondInstallment->status)->toBe(InstallmentStatus::Paid);
});

it('optionally restores applied store credit after a full Stripe refund', function (): void {
    $order = Order::factory()->completed()->create([
        'subtotal' => 6000,
        'total' => 5000,
        'credit_applied' => 1000,
        'stripe_payment_intent_id' => 'pi_credit_refund',
    ]);
    $grant = CreditGrant::factory()->create([
        'user_id' => $order->user_id,
        'initial_amount' => 1000,
        'remaining_amount' => 0,
    ]);
    CreditTransaction::factory()->checkoutDebit()->create([
        'user_id' => $order->user_id,
        'credit_grant_id' => $grant->id,
        'amount' => -1000,
        'reference_type' => $order->getMorphClass(),
        'reference_id' => $order->id,
    ]);
    bindSuccessfulOrderRefundStripe('re_credit');

    $refund = app(IssueOrderRefundAction::class)->handle(
        order: $order,
        processedBy: User::factory()->create(),
        amount: 5000,
        reason: 'Full cancellation including store credit.',
        restoreStoreCredit: true,
    );

    expect($grant->refresh()->remaining_amount)->toBe(1000)
        ->and($refund->credit_restored_at)->not->toBeNull();
});

it('keeps enrollments and order state unchanged when Stripe rejects the refund', function (): void {
    $course = Course::factory()->create();
    $product = Product::factory()->forCourse($course)->create(['price' => 5000]);
    $order = Order::factory()->completed()->create([
        'subtotal' => 5000,
        'total' => 5000,
        'stripe_payment_intent_id' => 'pi_failed_refund',
    ]);
    $orderItem = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
    ]);
    $enrollment = Enrollment::factory()->create([
        'order_item_id' => $orderItem->id,
        'course_id' => $course->id,
        'user_id' => $order->user_id,
    ]);
    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('refundPaymentIntent')->once()->andThrow(new Exception('Refund rejected.'));
    $this->app->instance(StripeServiceContract::class, $stripe);

    $refund = app(IssueOrderRefundAction::class)->handle(
        order: $order,
        processedBy: User::factory()->create(),
        amount: 5000,
        reason: 'Attempted cancellation.',
        enrollmentIds: [$enrollment->id],
    );

    expect($refund->status)->toBe(OrderRefundStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($enrollment->refresh())->toBeInstanceOf(Enrollment::class);
});

function stripeRefundForOrderTest(string $id, string $status = 'succeeded'): Refund
{
    return Refund::constructFrom([
        'id' => $id,
        'status' => $status,
        'failure_reason' => null,
    ]);
}

function bindSuccessfulOrderRefundStripe(string $refundId): void
{
    $stripe = Mockery::mock(StripeServiceContract::class);
    $stripe->shouldReceive('refundPaymentIntent')
        ->once()
        ->andReturn(stripeRefundForOrderTest($refundId));
    app()->instance(StripeServiceContract::class, $stripe);
}
