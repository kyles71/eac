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

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PaymentPlan');
    }

    public function update(AuthUser $authUser, PaymentPlan $paymentPlan): bool
    {
        return $authUser->can('Update:PaymentPlan');
    }

    public function delete(AuthUser $authUser, PaymentPlan $paymentPlan): bool
    {
        return $authUser->can('Delete:PaymentPlan');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PaymentPlan');
    }

    public function restore(AuthUser $authUser, PaymentPlan $paymentPlan): bool
    {
        return $authUser->can('Restore:PaymentPlan');
    }

    public function forceDelete(AuthUser $authUser, PaymentPlan $paymentPlan): bool
    {
        return $authUser->can('ForceDelete:PaymentPlan');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PaymentPlan');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PaymentPlan');
    }

    public function replicate(AuthUser $authUser, PaymentPlan $paymentPlan): bool
    {
        return $authUser->can('Replicate:PaymentPlan');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PaymentPlan');
    }
}
