<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\StoreCodeAttemptLimiterService;

it('limits failed code attempts per user for sixty seconds', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $attemptLimiter = app(StoreCodeAttemptLimiterService::class);

    foreach (range(1, 5) as $attempt) {
        $attemptLimiter->recordFailure($user);
    }

    expect($attemptLimiter->hasTooManyAttempts($user))->toBeTrue()
        ->and($attemptLimiter->secondsUntilAvailable($user))->toBeGreaterThan(0)
        ->and($attemptLimiter->hasTooManyAttempts($otherUser))->toBeFalse();

    $this->travel(61)->seconds();

    expect($attemptLimiter->hasTooManyAttempts($user))->toBeFalse();
});
