<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DiscountCodes\Schemas;

use App\Enums\DiscountType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class DiscountCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true)
                    ->placeholder('e.g., SUMMER20'),
                Select::make('type')
                    ->options(DiscountType::class)
                    ->required()
                    ->reactive(),
                TextInput::make('value')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->helperText(fn (callable $get): string => $get('type') === DiscountType::Percentage->value
                        ? 'Enter the percentage (e.g., 20 for 20% off)'
                        : 'Enter the amount in dollars (e.g., 10 for $10 off)')
                    ->prefix(fn (callable $get): ?string => $get('type') === DiscountType::FixedAmount->value ? '$' : null)
                    ->suffix(fn (callable $get): ?string => $get('type') === DiscountType::Percentage->value ? '%' : null)
                    ->formatStateUsing(function (?int $state, callable $get): ?string {
                        if ($state === null) {
                            return null;
                        }

                        if ($get('type') === DiscountType::FixedAmount->value) {
                            return number_format($state / 100, 2, '.', '');
                        }

                        return (string) $state;
                    })
                    ->dehydrateStateUsing(function (?string $state, callable $get): ?int {
                        if ($state === null) {
                            return null;
                        }

                        if ($get('type') === DiscountType::FixedAmount->value) {
                            return (int) round((float) $state * 100);
                        }

                        return (int) $state;
                    }),
                TextInput::make('min_order_amount')
                    ->label('Minimum Order Amount')
                    ->numeric()
                    ->prefix('$')
                    ->nullable()
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? number_format($state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn (?string $state): ?int => $state !== null && $state !== '' ? (int) round((float) $state * 100) : null)
                    ->helperText('Leave empty for no minimum.'),
                TextInput::make('max_uses')
                    ->label('Maximum Total Uses')
                    ->numeric()
                    ->nullable()
                    ->minValue(1)
                    ->helperText('Leave empty for unlimited uses.'),
                TextInput::make('max_uses_per_user')
                    ->label('Maximum Uses Per User')
                    ->numeric()
                    ->nullable()
                    ->minValue(1)
                    ->helperText('Leave empty for unlimited uses per user.'),
                DateTimePicker::make('expires_at')
                    ->label('Expiration Date')
                    ->nullable()
                    ->helperText('Leave empty for no expiration.'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Select::make('products')
                    ->label('Restrict to Products')
                    ->relationship(
                        name: 'products',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'),
                    )
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('Leave empty to apply to all products.'),
            ]);
    }
}
