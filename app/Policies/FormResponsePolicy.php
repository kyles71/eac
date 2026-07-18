<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormResponse;
use App\Models\User;

final readonly class FormResponsePolicy
{
    public function view(User $user, FormResponse $response): bool
    {
        $response->loadMissing('assignment');

        return $user->can('View:Form')
            || (
                $response->assignment->respondent_type === $user->getMorphClass()
                && (string) $response->assignment->respondent_id === (string) $user->getKey()
            );
    }
}
