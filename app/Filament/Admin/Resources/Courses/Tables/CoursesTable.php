<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Tables;

use App\Filament\Actions\SendEmailAction;
use App\Models\Course;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\SpatieTagsColumn;
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
                TextColumn::make('semester')
                    ->badge()
                    ->sortable(),
                TextColumn::make('capacity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('available_capacity')
                    ->label('Available')
                    ->state(fn (Course $record): int => $record->getAvailableCapacity())
                    ->badge()
                    ->color(fn (Course $record): string => $record->getAvailableCapacity() > 0 ? 'success' : 'danger'),
                TextColumn::make('start_time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('guest_teacher')
                    ->searchable(),
                TextColumn::make('teacher_display_name')
                    ->label('Teachers')
                    ->searchable(false)
                    ->sortable(false),
                SpatieTagsColumn::make('tags')
                    ->label('Course Tags')
                    ->type(Course::GENERAL_TAG_TYPE)
                    ->toggleable(),
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
                SendEmailAction::make()
                    ->to(fn ($record) => $record->purchasers->pluck('email')->all()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
