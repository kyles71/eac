<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Login;

it('records the most recent successful login without changing the profile update timestamp', function (): void {
    $updatedAt = now()->subDay()->startOfSecond();
    $loggedInAt = now()->startOfSecond();
    $user = User::factory()->create([
        'last_login_at' => null,
        'updated_at' => $updatedAt,
    ]);

    $this->travelTo($loggedInAt);

    event(new Login('web', $user, false));

    expect($user->refresh())
        ->last_login_at->toEqual($loggedInAt)
        ->updated_at->toEqual($updatedAt);
});
