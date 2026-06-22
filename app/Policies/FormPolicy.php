<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Form;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class FormPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Form');
    }

    public function view(AuthUser $authUser, Form $form): bool
    {
        return $authUser->can('View:Form');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Form');
    }

    public function update(AuthUser $authUser, Form $form): bool
    {
        return $authUser->can('Update:Form');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Form');
    }
}
