<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCardTypes\Tables;

use App\Filament\Actions\DeleteProductableAction;
use App\Filament\Actions\DeleteProductableBulkAction;
use App\Models\GiftCardType;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class GiftCardTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('denomination')
                    ->label('Denomination')
                    ->moneyCents()
                    ->sortable(),
                TextColumn::make('amount_type')
                    ->label('Amount Type')
                    ->state(fn (GiftCardType $record): string => $record->allows_custom_amount
                        ? 'Custom from '.$record->formattedMinimumCustomAmount()
                        : 'Fixed')
                    ->badge()
                    ->color(fn (GiftCardType $record): string => $record->allows_custom_amount ? 'info' : 'gray')
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('restriction')
                    ->label('Restriction')
                    ->state(fn (GiftCardType $record): string => $record->restrictionSummary())
                    ->searchable(false)
                    ->sortable(false)
                    ->badge()
                    ->color(fn (GiftCardType $record): string => $record->hasRestrictions() ? 'warning' : 'success'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                ActionGroup::make([
                    DeleteProductableAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteProductableBulkAction::make(),
                ]),
            ]);
    }
}
