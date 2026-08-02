<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CourseHolds\Tables;

use App\Filament\Admin\Resources\CourseHolds\CourseHoldResource;
use App\Models\CourseHold;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CourseHoldsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Hold #')
                    ->sortable(),
                TextColumn::make('user.full_name')
                    ->label('Family / User')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('status')
                    ->state(fn (CourseHold $record): string => $record->status()->getLabel())
                    ->badge()
                    ->color(fn (CourseHold $record): string => $record->status()->getColor()),
                TextColumn::make('seats_count')
                    ->label('Seats')
                    ->counts('seats')
                    ->badge(),
                TextColumn::make('available_seats')
                    ->label('Remaining')
                    ->state(fn (CourseHold $record): int => $record->availableSeatCount())
                    ->badge(),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('createdBy.full_name')
                    ->label('Created By')
                    ->placeholder('System')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    CourseHoldResource::editAction(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
