<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Tables;

use App\Models\Course;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('product.price')
                    ->label('Price')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? '$'.number_format($state / 100, 2) : '—')
                    ->placeholder('No product'),
                TextColumn::make('capacity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('available_capacity')
                    ->label('Available')
                    ->state(fn (Course $record): int => $record->availableCapacity())
                    ->badge()
                    ->color(fn (Course $record): string => $record->availableCapacity() > 0 ? 'success' : 'danger'),
                TextColumn::make('start_time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('guest_teacher')
                    ->searchable(),
                TextColumn::make('teacher.full_name')
                    ->sortable(),
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

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
