<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlans\Tables;

use App\Models\PaymentPlan;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PaymentPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('order.id')
                    ->label('Order')
                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                    ->sortable(),
                TextColumn::make('order.user.full_name')
                    ->label('Customer')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => '$'.number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('number_of_installments')
                    ->label('Installments')
                    ->sortable(),
                TextColumn::make('frequency')
                    ->badge(),
                TextColumn::make('method')
                    ->badge(),
                TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->state(fn (PaymentPlan $record): string => '$'.number_format($record->amountPaid() / 100, 2)),
                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->state(fn (PaymentPlan $record): string => '$'.number_format($record->remainingBalance() / 100, 2)),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
