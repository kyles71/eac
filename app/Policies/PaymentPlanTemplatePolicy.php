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

    public function view(AuthUser $authUser, PaymentPlanTemplate $paymentPlanTemplate): bool
    {
        return $authUser->can('View:PaymentPlanTemplate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PaymentPlanTemplate');
    }

    public function update(AuthUser $authUser, PaymentPlanTemplate $paymentPlanTemplate): bool
    {
        return $authUser->can('Update:PaymentPlanTemplate') && ! $paymentPlanTemplate->isUsed();
    }

    public function delete(AuthUser $authUser, PaymentPlanTemplate $paymentPlanTemplate): bool
    {
        return $authUser->can('Delete:PaymentPlanTemplate');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PaymentPlanTemplate');
    }

    public function restore(AuthUser $authUser, PaymentPlanTemplate $paymentPlanTemplate): bool
    {
        return $authUser->can('Restore:PaymentPlanTemplate');
    }

    public function forceDelete(AuthUser $authUser, PaymentPlanTemplate $paymentPlanTemplate): bool
    {
        return $authUser->can('ForceDelete:PaymentPlanTemplate');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PaymentPlanTemplate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PaymentPlanTemplate');
    }

    public function replicate(AuthUser $authUser, PaymentPlanTemplate $paymentPlanTemplate): bool
    {
        return $authUser->can('Replicate:PaymentPlanTemplate');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PaymentPlanTemplate');
    }
}
