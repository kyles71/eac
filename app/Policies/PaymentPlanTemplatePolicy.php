<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentPlanTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class PaymentPlanTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PaymentPlanTemplate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PaymentPlanTemplate');
    }

    public function update(AuthUser $authUser, PaymentPlanTemplate $paymentPlanTemplate): bool
    {
        return $authUser->can('Update:PaymentPlanTemplate') && ! $paymentPlanTemplate->isUsed();
    }
}
