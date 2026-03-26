<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCardTypes\Schemas;

use App\Enums\ProductType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

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
                    ->helperText('The face value of the gift card. The product price can differ for promotions.')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->dehydrateStateUsing(fn (string $state): int => (int) round((float) $state * 100))
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? number_format($state / 100, 2) : ''),
                Select::make('restricted_to_product_type')
                    ->label('Restrict to Product Type')
                    ->options(
                        collect(ProductType::cases())
                            ->reject(fn (ProductType $type): bool => $type === ProductType::Any || $type === ProductType::GiftCardType)
                            ->mapWithKeys(fn (ProductType $type): array => [$type->value => $type->getLabel()])
                            ->all()
                    )
                    ->nullable()
                    ->helperText('Leave empty for no product type restriction.'),
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
