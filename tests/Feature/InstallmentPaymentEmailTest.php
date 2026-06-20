<?php

declare(strict_types=1);

use App\Actions\Store\SendInstallmentPaymentEmail;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanFrequency;
use App\Models\Installment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPlan;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

it('registers customizable success and failure types with payment plan and order tokens', function (): void {
    $registry = app(EmailTypeRegistry::class);

    foreach (['payment-plan-installment-succeeded', 'payment-plan-installment-failed'] as $key) {
        $definition = $registry->get($key);

        expect($definition->category)->toBe('transactional')
            ->and(array_keys($definition->tokensByKey()))
            ->toContain(
                'stripe.payment_intent_id',
                'stripe.failure_reason',
                'installment.number',
                'payment_plan.remaining',
                'order.number',
                'order.total',
            )
            ->and(array_keys($definition->slotsByMergeTag()))
            ->toBe([
                'slot.payment-details',
                'slot.payment-plan-details',
                'slot.order-details',
            ]);
    }
});

it('renders escaped Stripe payment plan and order details for a failed payment', function (): void {
    Mail::fake();
    [$installment, $order] = installmentPaymentFixture();

    expect(app(SendInstallmentPaymentEmail::class)->handle(
        installment: $installment,
        successful: false,
        stripeStatus: 'requires_payment_method',
        stripePaymentIntentId: 'pi_failed_123',
        stripeCustomerId: 'cus_123',
        stripePaymentMethodId: 'pm_123',
        failureReason: 'Card declined <unsafe>',
        failureCode: 'card_declined',
    ))->toBeTrue();

    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail) use ($order): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'payment-plan-installment-failed'
            && $mail->hasTo($order->user->email)
            && $mail->usesMailer('transactional')
            && $rendered->subject === "Payment failed for order #{$order->id}"
            && str_contains($rendered->html, 'Card declined &lt;unsafe&gt;')
            && str_contains($rendered->html, 'pi_failed_123')
            && str_contains($rendered->html, 'cus_123')
            && str_contains($rendered->html, 'pm_123')
            && str_contains($rendered->html, 'card_declined')
            && str_contains($rendered->html, 'Paid')
            && str_contains($rendered->html, '$50.00')
            && str_contains($rendered->html, 'Remaining')
            && str_contains($rendered->html, '$100.00')
            && str_contains($rendered->html, 'Jazz &lt;script&gt;')
            && ! str_contains($rendered->html, '<script>');
    });
});

it('does not queue a payment result when that mail manager type is disabled', function (): void {
    Mail::fake();
    [$installment] = installmentPaymentFixture();

    app(ManagedTemplateRepository::class)->saveOverride('payment-plan-installment-failed', [
        'is_active' => false,
    ]);

    expect(app(SendInstallmentPaymentEmail::class)->handle(
        installment: $installment,
        successful: false,
        failureReason: 'Payment failed.',
    ))->toBeFalse();

    Mail::assertNothingQueued();
});

/**
 * @return array{Installment, Order}
 */
function installmentPaymentFixture(): array
{
    $user = User::factory()->create([
        'first_name' => 'Kyle',
        'email' => 'installments@example.com',
    ]);
    $order = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'subtotal' => 15000,
        'total' => 15000,
    ]);
    $product = Product::factory()->create(['name' => 'Jazz <script>']);
    OrderItem::factory()->fulfilled()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 15000,
        'total_price' => 15000,
    ]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'total_amount' => 15000,
        'number_of_installments' => 3,
        'frequency' => PaymentPlanFrequency::Monthly,
    ]);
    Installment::factory()->paid()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 1,
        'amount' => 5000,
    ]);
    $installment = Installment::factory()->create([
        'payment_plan_id' => $paymentPlan->id,
        'installment_number' => 2,
        'amount' => 5000,
        'due_date' => now(),
        'status' => InstallmentStatus::Failed,
        'retry_count' => 1,
    ]);

    return [$installment, $order];
}
