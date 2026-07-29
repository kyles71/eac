<?php

declare(strict_types=1);

use App\Models\Calendar;
use App\Models\Role;
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

it('renders the direct permission picker without JavaScript errors', function () {
    $user = User::factory()->create();

    visit("/admin/users/{$user->id}")
        ->click('Manage Access')
        ->assertSee('Direct Permissions')
        ->assertSee('Cards')
        ->assertSee('Select all')
        ->assertNoJavaScriptErrors();
});

it('updates teaching courses immediately after changing the teacher role', function (): void {
    $user = User::factory()->create();
    $teacherRole = Role::findByName('teacher');

    $page = visit("/admin/users/{$user->id}?relation=1");

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('.fi-sc-tabs .fi-tabs-item-label')]
            .some((label) => label.textContent.trim() === 'Courses')
        JS))->toBeFalse();

    $page
        ->click('Manage Access')
        ->check(".fi-modal-window input[wire\\:model=\"mountedActions.0.data.roles\"][value=\"{$teacherRole->id}\"]")
        ->click('.fi-modal-window .fi-ac-btn-action[type=submit]')
        ->wait(0.2)
        ->assertNoJavaScriptErrors();

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('.fi-sc-tabs .fi-tabs-item-label')]
            .some((label) => label.textContent.trim() === 'Courses')
        JS))->toBeTrue();
});

it('shows reactive password requirements while creating a user', function (): void {
    $page = visit('/admin/users')
        ->click('New User')
        ->assertSee('Password requirements:')
        ->assertNoJavaScriptErrors();

    expect($page->script(<<<'JS'
        document.querySelector('[data-password-requirement="minimum-length"]').classList.contains('text-gray-500')
        JS))->toBeTrue()
        ->and($page->script(<<<'JS'
            document.querySelector('[data-password-requirement="minimum-length"]').classList.contains('text-danger-600')
            JS))->toBeFalse()
        ->and($page->script(<<<'JS'
            getComputedStyle(document.querySelector('[data-password-requirement="maximum-length"]')).display
            JS))->toBe('none')
        ->and($page->script(<<<'JS'
            getComputedStyle(document.querySelector('[data-password-requirement="uncompromised"]')).display
            JS))->toBe('none');

    $page
        ->fill('mountedActionSchema0.password', 'short')
        ->keys('mountedActionSchema0.password', 'Tab');

    expect($page->script(<<<'JS'
        document.querySelector('[data-password-requirement="minimum-length"]').classList.contains('text-danger-600')
        JS))->toBeTrue();

    $page->fill('mountedActionSchema0.password', 'long-enough');

    expect($page->script(<<<'JS'
        document.querySelector('[data-password-requirement="minimum-length"]').classList.contains('text-success-600')
        JS))->toBeTrue();

    $page->script(<<<'JS'
        Alpine.$data(document.querySelector('[data-password-requirements]')).password = 'a'.repeat(256)
        JS);
    $page->wait(0.1);

    expect($page->script(<<<'JS'
        getComputedStyle(document.querySelector('[data-password-requirement="maximum-length"]')).display
        JS))->not->toBe('none');
});
