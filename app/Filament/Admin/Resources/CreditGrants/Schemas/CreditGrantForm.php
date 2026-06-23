<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CreditGrants\Schemas;

use App\Enums\ProductType;
use App\Models\Product;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CreditGrantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /** @return array<\Filament\Schemas\Components\Component> */
    public static function components(bool $includeRecipient = true): array
    {
        return [
            Section::make('Store Credit')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    ...($includeRecipient ? [
                        Select::make('user_id')
                            ->label('Recipient')
                            ->userRelationship('user')
                            ->required(),
                    ] : []),
                    TextInput::make('initial_amount')
                        ->label('Amount')
                        ->moneyCents(0.01)
                        ->required(),
                    TextInput::make('description')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    DatePicker::make('expires_on')
                        ->label('Expiration Date')
                        ->helperText('Optional. Credit remains valid through this date in Eastern time.')
                        ->minDate(now('America/New_York')->toDateString())
                        ->native(false),
                ]),
            Section::make('Restrictions')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('restricted_to_product_type')
                        ->label('Restrict to Product Type')
                        ->options(
                            collect(ProductType::cases())
                                ->reject(fn (ProductType $type): bool => in_array($type, [ProductType::Any, ProductType::GiftCardType], true))
                                ->mapWithKeys(fn (ProductType $type): array => [$type->value => $type->getLabel()])
                                ->all(),
                        )
                        ->helperText('Leave empty for no product type restriction.'),
                    Select::make('product_ids')
                        ->label('Restrict to Products')
                        ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('When both restrictions are set, a purchase must satisfy both.'),
                ]),
        ];
    }
}
