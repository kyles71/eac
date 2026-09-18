<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormAnswerGroup;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class FormAnswerGroupPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Form');
    }

    public function view(User $user, FormAnswerGroup $formAnswerGroup): bool
    {
        return $user->can('View:Form');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FormAnswerGroup $formAnswerGroup): bool
    {
        return false;
    }

    public function delete(User $user, FormAnswerGroup $formAnswerGroup): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
