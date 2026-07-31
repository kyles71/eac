<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudentCommunication;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

final class StudentCommunicationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('Send:Email');
    }

    public function view(User $user, StudentCommunication $studentCommunication): bool
    {
        return $user->can('Send:Email')
            && $user->can('view', $studentCommunication->student);
    }

    public function create(User $user): bool
    {
        return $user->can('Send:Email');
    }
}
