<?php

declare(strict_types=1);

use App\Actions\Store\SendPastDueInstallmentNotification;
use App\Models\Installment;
use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Kyle\FilamentMailManager\EmailTypeRegistry;
use Kyle\FilamentMailManager\Mail\ManagedMail;
use Kyle\FilamentMailManager\Repositories\ManagedTemplateRepository;

it('registers the customizable past-due type with user payment plan stripe and order data', function (): void {
    $definition = app(EmailTypeRegistry::class)->get('payment-plan-past-due');

    expect($definition->category)->toBe('transactional')
        ->and(array_keys($definition->tokensByKey()))
        ->toContain(
            'user.full_name',
            'user.email',
            'stripe.failure_reason',
            'installment.retry_count',
            'payment_plan.remaining',
            'order.number',
        );
});

it('queues one transactional administrator notification for a past-due installment', function (): void {
    Mail::fake();
    config()->set('mail.payment_plan_past_due_recipient', 'eacdance@outlook.com');
    $installment = pastDueInstallmentFixture();

    $action = app(SendPastDueInstallmentNotification::class);

    expect($action->handle($installment))->toBeTrue()
        ->and($action->handle($installment))->toBeFalse()
        ->and($installment->refresh()->past_due_notification_sent_at)->not->toBeNull();

    Mail::assertQueued(ManagedMail::class, 1);
    Mail::assertQueued(ManagedMail::class, function (ManagedMail $mail): bool {
        $rendered = $mail->getRenderedEmail();

        return $mail->emailTypeKey === 'payment-plan-past-due'
            && $mail->hasTo('eacdance@outlook.com')
            && $mail->usesMailer('transactional')
            && str_contains($rendered->subject, 'Payment plan past due for Jamie Dancer')
            && str_contains($rendered->html, 'jamie@example.com')
            && str_contains($rendered->html, 'insufficient_funds')
            && str_contains($rendered->html, 'pi_past_due');
    });
});

it('leaves the notification pending when its mail manager type is disabled', function (): void {
    Mail::fake();
    $installment = pastDueInstallmentFixture();
    app(ManagedTemplateRepository::class)->saveOverride('payment-plan-past-due', [
        'is_active' => false,
    ]);

    expect(app(SendPastDueInstallmentNotification::class)->handle($installment))->toBeFalse()
        ->and($installment->refresh()->past_due_notification_sent_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('sends outstanding past-due notifications from the command', function (): void {
    Mail::fake();
    $installment = pastDueInstallmentFixture();

    $this->artisan('installments:send-past-due-notifications')
        ->expectsOutput('Queued 1 past-due installment notification(s).')
        ->assertSuccessful();

    expect($installment->refresh()->past_due_notification_sent_at)->not->toBeNull();
    Mail::assertQueued(ManagedMail::class, 1);
});

function pastDueInstallmentFixture(): Installment
{
    $user = User::factory()->create([
        'first_name' => 'Jamie',
        'last_name' => 'Dancer',
        'email' => 'jamie@example.com',
        'stripe_id' => 'cus_past_due',
    ]);
    $order = Order::factory()->completed()->create([
        'user_id' => $user->id,
        'subtotal' => 10000,
        'total' => 10000,
    ]);
    $paymentPlan = PaymentPlan::factory()->create([
        'order_id' => $order->id,
        'stripe_customer_id' => 'cus_legacy',
        'stripe_payment_method_id' => 'pm_past_due',
    ]);

    return Installment::factory()->overdue()->create([
        'payment_plan_id' => $paymentPlan->id,
        'stripe_payment_intent_id' => 'pi_past_due',
        'last_attempted_at' => now(),
        'last_payment_status' => 'requires_payment_method',
        'last_failure_reason' => 'The payment method has insufficient funds.',
        'last_failure_code' => 'insufficient_funds',
    ]);
}
