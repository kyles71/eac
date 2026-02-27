<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCards\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class GiftCardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('initial_amount')
                    ->label('Initial')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('remaining_amount')
                    ->label('Remaining')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('purchasedBy.email')
                    ->label('Purchased By')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('redeemedBy.email')
                    ->label('Redeemed By')
                    ->placeholder('Not redeemed')
                    ->searchable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('redeemed_at')
                    ->label('Redeemed At')
                    ->dateTime()
                    ->placeholder('Not redeemed')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TernaryFilter::make('redeemed')
                    ->label('Redeemed')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('redeemed_at'),
                        false: fn ($query) => $query->whereNull('redeemed_at'),
                    ),
            ])
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
