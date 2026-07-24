<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Filament\Shared\Pages\Profile\Profile;
use App\Models\User;
use App\Support\MediaDisks;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('user');
});

it('registers the shared profile page in both panels at the existing URLs', function (): void {
    expect(route('filament.admin.auth.profile', absolute: false))->toBe('/admin/my-profile')
        ->and(route('filament.user.auth.profile', absolute: false))->toBe('/dancefam/my-profile');

    $this->get('/admin/my-profile')->assertOk();
    $this->get('/dancefam/my-profile')->assertOk();
});

it('renders separate personal information and password forms', function (): void {
    livewire(Profile::class)
        ->assertOk()
        ->assertSee('Personal information')
        ->assertSee('Update password')
        ->assertSee('Two-factor authentication')
        ->assertSchemaExists('personalInformationForm')
        ->assertSchemaExists('passwordForm')
        ->assertSchemaComponentExists(
            'avatar',
            'personalInformationForm',
            fn (SpatieMediaLibraryFileUpload $field): bool => $field->getDiskName() === MediaDisks::private()
                && $field->getVisibility() === 'private',
        );
});

it('stores the profile avatar on the private media disk', function (): void {
    Storage::fake(MediaDisks::private());

    /** @var User $user */
    $user = auth()->user();

    livewire(Profile::class)
        ->fillForm([
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ], 'personalInformationForm')
        ->call('savePersonalInformation')
        ->assertHasNoFormErrors([], 'personalInformationForm');

    expect($user->refresh()->getFirstMedia('avatars'))
        ->not->toBeNull()
        ->disk->toBe(MediaDisks::private());
});

it('updates personal information without validating the password form', function (): void {
    /** @var User $user */
    $user = auth()->user();

    livewire(Profile::class)
        ->fillForm([
            'first_name' => 'Katherine',
            'last_name' => 'Dunham',
            'email' => 'katherine@example.com',
        ], 'personalInformationForm')
        ->call('savePersonalInformation')
        ->assertHasNoFormErrors([], 'personalInformationForm')
        ->assertNotified('Personal information saved');

    expect($user->refresh())
        ->first_name->toBe('Katherine')
        ->last_name->toBe('Dunham')
        ->email->toBe('katherine@example.com');
});

it('validates personal information independently', function (): void {
    $existingUser = User::factory()->create();

    livewire(Profile::class)
        ->fillForm([
            'first_name' => null,
            'email' => $existingUser->email,
        ], 'personalInformationForm')
        ->call('savePersonalInformation')
        ->assertHasFormErrors([
            'first_name' => 'required',
            'email' => 'unique',
        ], 'personalInformationForm');
});

it('updates the password with the current password', function (): void {
    /** @var User $user */
    $user = auth()->user();

    livewire(Profile::class)
        ->fillForm([
            'current_password' => config('app.default_user.password'),
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
        ], 'passwordForm')
        ->call('savePassword')
        ->assertHasNoFormErrors([], 'passwordForm')
        ->assertNotified('Password updated');

    expect(Hash::check('a-new-secure-password', $user->refresh()->password))->toBeTrue();
});

it('rejects a password update when the current password is wrong', function (): void {
    livewire(Profile::class)
        ->fillForm([
            'current_password' => 'wrong-password',
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
        ], 'passwordForm')
        ->call('savePassword')
        ->assertHasFormErrors(['current_password'], 'passwordForm');
});

it('uses one recoverable native authenticator enrollment across both panels', function (): void {
    /** @var User $user */
    $user = auth()->user();

    expect($user)
        ->toBeInstanceOf(HasAppAuthentication::class)
        ->toBeInstanceOf(HasAppAuthenticationRecovery::class);

    $adminProvider = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'];
    $userProvider = Filament::getPanel('user')->getMultiFactorAuthenticationProviders()['app'];

    expect($adminProvider)
        ->toBeInstanceOf(AppAuthentication::class)
        ->isRecoverable()->toBeTrue()
        ->and($userProvider)
        ->toBeInstanceOf(AppAuthentication::class)
        ->isRecoverable()->toBeTrue();

    $user->saveAppAuthenticationSecret('shared-native-secret');

    expect($adminProvider->isEnabled($user->refresh()))->toBeTrue()
        ->and($userProvider->isEnabled($user))->toBeTrue();
});

it('shows native authenticator status on the admin user view', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->create();
    $user->saveAppAuthenticationSecret('native-secret');

    livewire(ViewUser::class, ['record' => $user->getKey()])
        ->assertSchemaStateSet([
            'uses_mfa' => true,
        ], 'infolist');
});
