<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class FormVersionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Form');
    }

    public function view(User $user, FormVersion $formVersion): bool
    {
        return $user->can('View:Form');
    }

    public function create(User $user): bool
    {
        return $user->can('Update:Form');
    }

    public function update(User $user, FormVersion $formVersion): bool
    {
        return $user->can('Update:Form');
    }

    public function delete(User $user, FormVersion $formVersion): bool
    {
        return $user->can('Update:Form');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('Update:Form');
    }

    public function restore(User $user, FormVersion $formVersion): bool
    {
        return false;
    }

    public function forceDelete(User $user, FormVersion $formVersion): bool
    {
        return false;
    }
}
