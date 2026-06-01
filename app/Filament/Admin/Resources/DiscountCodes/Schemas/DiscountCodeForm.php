<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DiscountCodes\Schemas;

use App\Enums\DiscountType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class DiscountCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Discount')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
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
                            ->label('Discount')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->helperText(fn (callable $get): string => self::isPercentage($get('type'))
                                ? 'Enter the percentage (e.g., 20 for 20% off)'
                                : 'Enter the amount in dollars (e.g., 10 for $10 off)')
                            ->prefix(fn (callable $get): ?string => self::isFixedAmount($get('type')) ? '$' : null)
                            ->suffix(fn (callable $get): ?string => self::isPercentage($get('type')) ? '%' : null)
                            ->formatStateUsing(function (?int $state, callable $get): ?string {
                                if ($state === null) {
                                    return null;
                                }

                                if (self::isFixedAmount($get('type'))) {
                                    return number_format($state / 100, 2, '.', '');
                                }

                                return (string) $state;
                            })
                            ->dehydrateStateUsing(function (mixed $state, callable $get): ?int {
                                if (blank($state)) {
                                    return null;
                                }

                                if (self::isFixedAmount($get('type'))) {
                                    return (int) round(((float) str_replace(',', '', (string) $state)) * 100);
                                }

                                return (int) $state;
                            }),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
                Section::make('Limits')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('min_order_amount')
                            ->label('Minimum Order Amount')
                            ->moneyCents()
                            ->nullable()
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
                    ]),
                Section::make('Product Restrictions')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('products')
                            ->label('Restrict to Products')
                            ->relationship(
                                name: 'products',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'),
                            )
                            ->multiple()
                            ->preload()
                            ->helperText('Leave empty to apply to all products.'),
                    ]),
            ]);
    }

    private static function isFixedAmount(mixed $type): bool
    {
        return $type === DiscountType::FixedAmount || $type === DiscountType::FixedAmount->value;
    }

    private static function isPercentage(mixed $type): bool
    {
        return $type === DiscountType::Percentage || $type === DiscountType::Percentage->value;
    }
}
