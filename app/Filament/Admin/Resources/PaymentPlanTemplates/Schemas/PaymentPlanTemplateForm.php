<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlanTemplates\Schemas;

use App\Enums\PaymentPlanFrequency;
use App\Enums\ProductType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class PaymentPlanTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('product_type')
                    ->label('Product Type')
                    ->options(ProductType::class)
                    ->required(),
                TextInput::make('min_price')
                    ->label('Min Price (cents)')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                TextInput::make('max_price')
                    ->label('Max Price (cents)')
                    ->numeric()
                    ->required()
                    ->minValue(0),
                TextInput::make('number_of_installments')
                    ->label('Number of Installments')
                    ->numeric()
                    ->required()
                    ->minValue(2)
                    ->maxValue(24),
                Select::make('frequency')
                    ->options(PaymentPlanFrequency::class)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
