<?php

declare(strict_types=1);

use App\Models\Calendar;
use App\Models\User;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    Calendar::factory()->create();
    $this->withVite();
});

it('can create a new user', function () {
    $user = User::factory()->make();

    visit('/admin/users')
        ->click('New User')
        ->assertSee('Create User')
        ->fill('mountedActionSchema0.first_name', $user->first_name)
        ->fill('mountedActionSchema0.last_name', $user->last_name)
        ->fill('mountedActionSchema0.email', $user->email)
        ->fill('mountedActionSchema0.password', 'password')
        ->click('.fi-modal-window .fi-ac-btn-action[type=submit]')
        ->assertSee('Created');

    assertDatabaseHas('users', [
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => $user->email,
    ]);
});

it('can edit an existing user', function () {
    $existingUser = User::factory()->create();
    $newData = User::factory()->make();

    visit("/admin/users/{$existingUser->id}")
        ->click('Edit')
        ->assertSee('Save')
        ->fill('mountedActionSchema0.first_name', $newData->first_name)
        ->fill('mountedActionSchema0.last_name', $newData->last_name)
        ->click('.fi-modal-window .fi-ac-btn-action[type=submit]')
        ->assertSee('Saved');

    assertDatabaseHas('users', [
        'id' => $existingUser->id,
        'first_name' => $newData->first_name,
        'last_name' => $newData->last_name,
    ]);
});
