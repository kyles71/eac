<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    auth()->logout();

    $this->user = User::factory()->create([
        'email' => 'demo@pestphp.com',
        'password' => 'password',
    ]);

    $this->user->assignRole('super_admin');
});

test('an unauthenticated user can login', function () {
    visit('/admin/login')
        ->fill('[id="form.email"]', $this->user->email)
        ->fill('[id="form.password"]', 'password')
        ->submit()
        ->assertSee('Dashboard');

    $this->assertAuthenticated();
});
