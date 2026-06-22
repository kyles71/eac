<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormUser;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class FormUserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FormUser');
    }

    public function view(AuthUser $authUser, FormUser $formUser): bool
    {
        return $authUser->can('View:FormUser');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FormUser');
    }

    public function update(AuthUser $authUser, FormUser $formUser): bool
    {
        return $authUser->can('Update:FormUser');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FormUser');
    }
}
