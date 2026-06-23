<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CreditGrant;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class CreditGrantPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:CreditGrant');
    }

    public function view(User $user, CreditGrant $creditGrant): bool
    {
        return $user->can('View:CreditGrant');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:CreditGrant');
    }

    public function update(User $user, CreditGrant $creditGrant): bool
    {
        return false;
    }

    public function delete(User $user, CreditGrant $creditGrant): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function revoke(User $user, CreditGrant $creditGrant): bool
    {
        return $user->can('Revoke:CreditGrant');
    }
}
