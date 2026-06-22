<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Models\User;
use App\Support\MediaDisks;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('avatar')
                            ->collection('avatars')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->image()
                            ->avatar()
                            ->circleCropper()
                            ->columnSpanFull(),
                        TextInput::make('first_name')
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('last_name')
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('email')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->email()
                            ->required(),
                    ]),
                Section::make('Security')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('password')
                            ->password()
                            ->required(fn ($record): bool => $record === null)
                            ->revealable(filament()->arePasswordsRevealable())
                            ->rule(Password::default())
                            ->autocomplete('new-password')
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->dehydrateStateUsing(fn ($state): string => Hash::make($state)),
                    ]),
                Section::make('Staff Profile')
                    ->collapsed()
                    ->columnSpanFull()
                    ->visible(fn (?User $record): bool => $record?->isStaffMember() ?? false)
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('staff_photo')
                            ->label('Staff Photo')
                            ->collection('staff-photo')
                            ->disk(MediaDisks::private())
                            ->visibility('private')
                            ->image()
                            ->columnSpanFull(),
                        Textarea::make('staff_bio')
                            ->label('Staff Bio')
                            ->maxLength(500)
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
