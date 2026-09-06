<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentPlan;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class PaymentPlanPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PaymentPlan');
    }

    public function view(AuthUser $authUser, PaymentPlan $paymentPlan): bool
    {
        return $authUser->can('View:PaymentPlan');
    }

    public function adjustDueDates(AuthUser $authUser, PaymentPlan $paymentPlan): bool
    {
        return $authUser->can('AdjustDueDates:PaymentPlan')
            && $paymentPlan->hasReschedulableInstallments();
    }

    public function retryPayment(AuthUser $authUser, PaymentPlan $paymentPlan): bool
    {
        return $authUser->can('RetryPayment:PaymentPlan')
            && $paymentPlan->hasCollectibleMissedInstallments();
    }

    public function sendPaymentLink(AuthUser $authUser, PaymentPlan $paymentPlan): bool
    {
        return $authUser->can('SendPaymentLink:PaymentPlan')
            && $paymentPlan->hasCollectibleMissedInstallments();
    }
}
