<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCards\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class GiftCardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('initial_amount')
                    ->label('Initial Amount ($)')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->dehydrateStateUsing(fn (string $state): int => (int) round((float) $state * 100))
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? number_format($state / 100, 2) : ''),
                TextInput::make('remaining_amount')
                    ->label('Remaining Amount ($)')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->dehydrateStateUsing(fn (string $state): int => (int) round((float) $state * 100))
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? number_format($state / 100, 2) : ''),
                TextInput::make('purchased_by_user_id')
                    ->label('Purchased By (User ID)')
                    ->required()
                    ->numeric(),
                TextInput::make('redeemed_by_user_id')
                    ->label('Redeemed By (User ID)')
                    ->numeric()
                    ->nullable(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
