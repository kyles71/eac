<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormAssignment;
use App\Models\FormResponse;
use App\Models\User;

final readonly class FormResponsePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('View:Form');
    }

    public function view(User $user, FormResponse $response): bool
    {
        $response->loadMissing('assignment');
        $assignment = $response->assignment;

        return $user->can('View:Form')
            || ($assignment instanceof FormAssignment && $assignment->isAccessibleBy($user));
    }
}
