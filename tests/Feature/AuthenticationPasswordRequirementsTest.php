<?php

declare(strict_types=1);

use App\Filament\Shared\Pages\Auth\ResetPassword;
use App\Filament\Shared\Pages\Profile\Profile;
use App\Filament\User\Pages\Auth\Register;
use Filament\Facades\Filament;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Pest\Livewire\livewire;

it('uses the centralized password validation requirements', function (): void {
    expect(Validator::make(
        ['password' => 'short7'],
        ['password' => Password::default()],
    )->fails())->toBeTrue()
        ->and(Validator::make(
            ['password' => Str::repeat('a', 256)],
            ['password' => Password::default()],
        )->fails())->toBeTrue()
        ->and(Validator::make(
            ['password' => 'R4nd0m!x'],
            ['password' => Password::default()],
        )->passes())->toBeTrue();
});

it('rejects passwords found in a known data breach', function (): void {
    app()->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
    {
        public function verify($data): bool
        {
            return false;
        }
    });

    $validator = Validator::make(
        ['password' => 'R4nd0m!x'],
        ['password' => Password::default()],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->get('password'))
        ->toContain('The given password has appeared in a data leak. Please choose a different password.');
});

it('shows every password requirement on registration', function (): void {
    Filament::setCurrentPanel('user');
    auth()->logout();

    livewire(Register::class)
        ->assertSee('Password requirements:')
        ->assertSee('At least 8 characters')
        ->assertSee('No more than 255 characters')
        ->assertSee('Not found in a known data breach');
});

it('marks a compromised password as broken after validation', function (): void {
    app()->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
    {
        public function verify($data): bool
        {
            return false;
        }
    });

    Filament::setCurrentPanel('user');
    auth()->logout();

    livewire(Register::class)
        ->fillForm([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'register@example.com',
            'password' => 'R4nd0m!x',
            'passwordConfirmation' => 'R4nd0m!x',
        ])
        ->call('register')
        ->assertHasFormErrors(['password'])
        ->assertSeeHtml('hasCompromisedPasswordError: true');
});

it('shows every password requirement on password reset in both panels', function (): void {
    expect(Filament::getPanel('admin')->getResetPasswordRouteAction())->toBe(ResetPassword::class)
        ->and(Filament::getPanel('user')->getResetPasswordRouteAction())->toBe(ResetPassword::class);

    Filament::setCurrentPanel('user');
    auth()->logout();

    livewire(ResetPassword::class, [
        'email' => 'user@example.com',
        'token' => 'test-token',
    ])
        ->assertSee('At least 8 characters')
        ->assertSee('No more than 255 characters')
        ->assertSee('Not found in a known data breach');
});

it('shows every password requirement on the profile password form', function (): void {
    Filament::setCurrentPanel('user');

    livewire(Profile::class)
        ->assertSee('At least 8 characters')
        ->assertSee('No more than 255 characters')
        ->assertSee('Not found in a known data breach');
});
