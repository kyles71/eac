<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlanTemplates\Schemas;

use App\Enums\CourseSemester;
use App\Enums\PaymentPlanFrequency;
use App\Enums\ProductType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('course_semesters', null);
                    }),
                Select::make('course_semesters')
                    ->label('Course Semesters')
                    ->options(CourseSemester::class)
                    ->multiple()
                    ->searchable(false)
                    ->helperText('Leave blank to allow all course semesters.')
                    ->visible(fn (Get $get): bool => self::isCourseProductType($get('product_type')))
                    ->dehydrateStateUsing(fn (?array $state, Get $get): ?array => self::isCourseProductType($get('product_type')) && filled($state)
                        ? array_values($state)
                        : null)
                    ->columnSpanFull(),
                TextInput::make('min_price')
                    ->label('Min Price')
                    ->moneyCents()
                    ->required(),
                TextInput::make('max_price')
                    ->label('Max Price')
                    ->moneyCents()
                    ->required(),
                Section::make('Installments')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('number_of_installments')
                            ->label('Number of Installments')
                            ->numeric()
                            ->required()
                            ->minValue(2)
                            ->maxValue(24),
                        Select::make('frequency')
                            ->options(fn (): array => PaymentPlanFrequency::optionsForEnvironment())
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
            ]);
    }

    private static function isCourseProductType(mixed $productType): bool
    {
        return $productType === ProductType::Course
            || $productType === ProductType::Course->value;
    }
}
