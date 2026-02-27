<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DiscountCodes\Tables;

use App\Enums\DiscountType;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class DiscountCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (DiscountType $state): string => match ($state) {
                        DiscountType::Percentage => 'info',
                        DiscountType::FixedAmount => 'success',
                    }),
                TextColumn::make('value')
                    ->label('Discount')
                    ->formatStateUsing(function (int $state, $record): string {
                        return $record->formattedValue();
                    })
                    ->sortable(),
                TextColumn::make('times_used')
                    ->label('Uses')
                    ->formatStateUsing(fn (int $state, $record): string => $record->max_uses !== null
                        ? "{$state} / {$record->max_uses}"
                        : (string) $state)
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->options(DiscountType::class),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ]);
    }
}
