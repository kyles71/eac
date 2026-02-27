<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCardTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class GiftCardTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('denomination')
                    ->label('Denomination ($)')
                    ->helperText('Set to 0 for custom-amount gift cards.')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->default(0)
                    ->dehydrateStateUsing(fn (string $state): int => (int) round((float) $state * 100))
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? number_format($state / 100, 2) : '0.00'),
            ]);
    }
}
