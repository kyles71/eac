<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\Mail\QueueManagedEmail;
use App\Filament\User\Pages\PayPaymentPlan;
use App\Models\PaymentPlan;
use App\Models\User;

final readonly class SendPaymentPlanPayNowLink
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
    ) {}

    public function handle(PaymentPlan $paymentPlan): bool
    {
        $paymentPlan->loadMissing('order.user');
        $user = $paymentPlan->order?->user;

        if (! $user instanceof User || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $url = PayPaymentPlan::getUrl(['paymentPlan' => $paymentPlan], panel: 'user');

        return $this->managedEmail->handle(
            recipients: $user->email,
            emailTypeKey: 'payment-plan-pay-now-link',
            tokens: [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $user->first_name,
                'payment_plan.number' => (string) $paymentPlan->id,
                'order.number' => (string) $paymentPlan->order_id,
            ],
            slots: [
                'action' => '<a href="'.e($url).'" style="display:inline-block;padding:12px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px">Pay missed installments</a>',
            ],
        );
    }
}
