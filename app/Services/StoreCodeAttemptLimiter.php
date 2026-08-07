<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

final readonly class StoreCodeAttemptLimiter
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function hasTooManyAttempts(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->key($user), self::MAX_ATTEMPTS);
    }

    public function secondsUntilAvailable(User $user): int
    {
        return RateLimiter::availableIn($this->key($user));
    }

    public function recordFailure(User $user): void
    {
        RateLimiter::hit($this->key($user), self::DECAY_SECONDS);
    }

    private function key(User $user): string
    {
        return "store-code-attempts:user:{$user->getKey()}";
    }
}
