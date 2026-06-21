<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Profile;

use App\Models\User;
use App\Support\MediaDisks;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * @property-read Schema $passwordForm
 * @property-read Schema $personalInformationForm
 */
final class Profile extends EditProfile
{
    use RestrictsFileUploadsToSchemaComponents;

    /** @var array<string, mixed> */
    public array $passwordData = [];

    /** @var array<string, mixed> */
    public array $personalInformationData = [];

    protected static ?string $slug = 'my-profile';

    public static function getLabel(): string
    {
        return 'My Profile';
    }

    public function getSubheading(): string
    {
        return 'Manage your personal information, password, and account security.';
    }

    public function mount(): void
    {
        $this->getUser()->refresh();

        $this->personalInformationForm->fill($this->getUser()->attributesToArray());
        $this->passwordForm->fill();
    }

    public function personalInformationForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                SpatieMediaLibraryFileUpload::make('avatar')
                    ->collection('avatars')
                    ->disk(MediaDisks::private())
                    ->visibility('private')
                    ->hiddenLabel()
                    ->image()
                    ->avatar()
                    ->columnSpan(1),
                Group::make([
                    TextInput::make('first_name')
                        ->required()
                        ->maxLength(255)
                        ->autofocus(),
                    TextInput::make('last_name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(User::class, 'email', ignoreRecord: true),
                ])->columnSpan(2),
            ])
            ->columns(3)
            ->model($this->getUser())
            ->operation('edit')
            ->statePath('personalInformationData');
    }

    public function passwordForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('current_password')
                    ->label('Current password')
                    ->password()
                    ->revealable(Filament::arePasswordsRevealable())
                    ->currentPassword(guard: Filament::getAuthGuard())
                    ->autocomplete('current-password')
                    ->required(),
                TextInput::make('password')
                    ->label('New password')
                    ->password()
                    ->revealable(Filament::arePasswordsRevealable())
                    ->rule(Password::default())
                    ->showAllValidationMessages()
                    ->autocomplete('new-password')
                    ->same('password_confirmation')
                    ->required(),
                TextInput::make('password_confirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->revealable(Filament::arePasswordsRevealable())
                    ->autocomplete('new-password')
                    ->required(),
            ])
            ->statePath('passwordData');
    }

    public function savePersonalInformation(): void
    {
        if (! $this->canProceedAfterRateLimit('savePersonalInformation')) {
            return;
        }

        $data = $this->personalInformationForm->getState();
        $user = $this->getUser();

        $user->update(Arr::only($data, ['first_name', 'last_name', 'email']));
        $this->personalInformationForm->model($user)->saveRelationships();

        $user->refresh();
        $this->personalInformationForm->fill($user->attributesToArray());

        Notification::make()
            ->title('Personal information saved')
            ->success()
            ->send();
    }

    public function savePassword(): void
    {
        if (! $this->canProceedAfterRateLimit('savePassword')) {
            return;
        }

        $data = $this->passwordForm->getState();
        $user = $this->getUser();

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
        ])->save();

        if (request()->hasSession()) {
            request()->session()->put(
                'password_hash_'.Filament::getAuthGuard(),
                $user->getAuthPassword(),
            );
        }

        $this->passwordForm->fill();

        Notification::make()
            ->title('Password updated')
            ->success()
            ->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Personal information')
                    ->description('Update your name, email address, and profile photo.')
                    ->aside()
                    ->schema([
                        Form::make([EmbeddedSchema::make('personalInformationForm')])
                            ->id('personal-information-form')
                            ->livewireSubmitHandler('savePersonalInformation')
                            ->footer([
                                Actions::make([
                                    Action::make('savePersonalInformation')
                                        ->label('Save personal information')
                                        ->submit('savePersonalInformation'),
                                ])
                                    ->alignment(Alignment::End)
                                    ->key('personal-information-actions'),
                            ]),
                    ]),
                Section::make('Update password')
                    ->description('Use a long, random password to keep your account secure.')
                    ->aside()
                    ->schema([
                        Form::make([EmbeddedSchema::make('passwordForm')])
                            ->id('password-form')
                            ->livewireSubmitHandler('savePassword')
                            ->footer([
                                Actions::make([
                                    Action::make('savePassword')
                                        ->label('Update password')
                                        ->submit('savePassword'),
                                ])
                                    ->alignment(Alignment::End)
                                    ->key('password-actions'),
                            ]),
                    ]),
                ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
            ]);
    }

    public function getMultiFactorAuthenticationContentComponent(): ?Component
    {
        $section = parent::getMultiFactorAuthenticationContentComponent();

        if (! $section instanceof Section) {
            return $section;
        }

        return $section
            ->label(null)
            ->heading('Two-factor authentication')
            ->description('Add an authenticator app as an extra layer of account security.')
            ->aside()
            ->compact(false)
            ->secondary(false);
    }

    private function canProceedAfterRateLimit(string $method): bool
    {
        try {
            $this->rateLimit(5, method: $method);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return false;
        }

        return true;
    }
}
