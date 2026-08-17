<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\SecurityAuditLogger;
use Illuminate\Auth\Events\Failed;

final readonly class LogFailedAuthentication
{
    public function __construct(private SecurityAuditLogger $securityAuditLogger) {}

    public function handle(Failed $event): void
    {
        $this->securityAuditLogger->authenticationFailed($event);
    }
}
