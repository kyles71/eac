<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->maxLength(255)
                    ->required(),
                TextInput::make('last_name')
                    ->maxLength(255)
                    ->required(),
                TextInput::make('email')
                    ->maxLength(255)
                    ->unique()
                    ->email()
                    ->required(),
                TextInput::make('password')
                    ->password()
                    ->required(fn ($record): bool => $record === null)
                    ->revealable(filament()->arePasswordsRevealable())
                    ->rule(Password::default())
                    ->autocomplete('new-password')
                    ->dehydrated(fn ($state): bool => filled($state))
                    ->dehydrateStateUsing(fn ($state): string => Hash::make($state)),
            ]);
    }
}
