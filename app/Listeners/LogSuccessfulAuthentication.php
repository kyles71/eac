<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\SecurityAuditLogger;
use Illuminate\Auth\Events\Login;

final readonly class LogSuccessfulAuthentication
{
    public function __construct(private SecurityAuditLogger $securityAuditLogger) {}

    public function handle(Login $event): void
    {
        $this->securityAuditLogger->authenticationSucceeded($event);
    }
}
