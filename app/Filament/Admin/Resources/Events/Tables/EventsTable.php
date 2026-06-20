<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Tables;

use App\Filament\Actions\CancelEventAction;
use App\Models\Event;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('cancellation_status')
                    ->label('Status')
                    ->state(fn (Event $record): string => $record->isCancelled() ? 'Cancelled' : 'Scheduled')
                    ->badge()
                    ->color(fn (Event $record): string => $record->isCancelled() ? 'danger' : 'success')
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('start_time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('end_time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('course.name')
                    ->label('Course')
                    ->searchable(),
                TextColumn::make('calendar.name')
                    ->label('Calendar')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                CancelEventAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
