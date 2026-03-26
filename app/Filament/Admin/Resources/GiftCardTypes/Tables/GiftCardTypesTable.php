<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCardTypes\Tables;

use App\Models\GiftCardType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('restriction')
                    ->label('Restriction')
                    ->state(fn (GiftCardType $record): string => $record->restrictionSummary())
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
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
