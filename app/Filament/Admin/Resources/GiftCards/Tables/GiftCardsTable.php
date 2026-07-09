<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCards\Tables;

use App\Models\GiftCard;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                TextColumn::make('giftCardType.name')
                    ->label('Type')
                    ->placeholder('Custom / legacy')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('initial_amount')
                    ->label('Initial')
                    ->moneyCents()
                    ->sortable(),
                TextColumn::make('remaining_amount')
                    ->label('Remaining')
                    ->moneyCents()
                    ->sortable(),
                TextColumn::make('redemption_status')
                    ->label('Status')
                    ->state(fn (GiftCard $record): string => self::status($record))
                    ->badge()
                    ->color(fn (GiftCard $record): string => self::statusColor(self::status($record)))
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('purchasedBy.full_name')
                    ->label('Purchased By')
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('purchasedBy.email')
                    ->label('Purchaser Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('redeemedBy.full_name')
                    ->label('Redeemed By')
                    ->placeholder('Not redeemed')
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->toggleable(),
                TextColumn::make('redeemedBy.email')
                    ->label('Redeemer Email')
                    ->placeholder('Not redeemed')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order.id')
                    ->label('Order')
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? "#{$state}" : null)
                    ->placeholder('No order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('gift_card_type_id')
                    ->label('Type')
                    ->relationship('giftCardType', 'name')
                    ->searchable()
                    ->preload(),
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

    private static function status(GiftCard $giftCard): string
    {
        return match (true) {
            ! $giftCard->is_active => 'Inactive',
            $giftCard->redeemed_at !== null => 'Redeemed',
            $giftCard->remaining_amount <= 0 => 'Used',
            default => 'Redeemable',
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'Redeemable' => 'success',
            'Redeemed' => 'info',
            'Inactive' => 'gray',
            default => 'warning',
        };
    }
}
