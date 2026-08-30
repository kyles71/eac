<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Support\SecurityAuditLogger;

final readonly class UserObserver
{
    public function __construct(private SecurityAuditLogger $securityAuditLogger) {}

    public function updated(User $user): void
    {
        if ($user->wasChanged('password')) {
            $this->securityAuditLogger->passwordChanged($user);
        }
    }
}
