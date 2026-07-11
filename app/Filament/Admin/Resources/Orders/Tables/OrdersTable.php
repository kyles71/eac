<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Order #')
                    ->sortable()
                    ->copyable(),
                TextColumn::make('user.full_name')
                    ->label('Customer')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('order_items_count')
                    ->label('Items')
                    ->counts('orderItems')
                    ->badge(),
                TextColumn::make('total')
                    ->moneyCents()
                    ->sortable(),
                TextColumn::make('paymentPlanTemplate.name')
                    ->label('Payment Plan')
                    ->placeholder('Paid in full')
                    ->toggleable(),
                TextColumn::make('discountCode.code')
                    ->label('Discount')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatus::class),
            ])
            ->recordActions([

            ])
            ->toolbarActions([

            ]);
    }
}
