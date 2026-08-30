<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Order;
use App\Models\PaymentPlan;
use App\Models\User;
use LogicException;

final readonly class PaymentPlanScheduleAdjustedContentService
{
    /**
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(PaymentPlan $paymentPlan, string $reason): array
    {
        $paymentPlan->loadMissing(['installments', 'order.user']);
        $order = $paymentPlan->order;
        $user = $order?->user;

        if (! $order instanceof Order || ! $user instanceof User) {
            throw new LogicException('The payment plan order and customer are required.');
        }

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'user.first_name' => $user->first_name,
                'user.full_name' => $user->full_name,
                'adjustment.reason' => $reason,
                'payment_plan.number' => (string) $paymentPlan->id,
                'order.number' => (string) $order->id,
            ],
            'slots' => [
                'revised-schedule' => view('mail.payment-plan-revised-schedule', [
                    'installments' => $paymentPlan->installments->sortBy('installment_number'),
                ])->render(),
            ],
        ];
    }
}
