<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EventSubstituteRequest;
use App\Models\User;

final class EventSubstituteRequestPolicy
{
    public function view(User $user, EventSubstituteRequest $eventSubstituteRequest): bool
    {
        return $eventSubstituteRequest->teacher_id === $user->id;
    }
}
