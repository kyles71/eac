<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\StudentCommunications\Tables;

use App\Enums\StopLightColor;
use App\Enums\StudentCommunicationType;
use App\Models\StudentCommunication;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StudentCommunicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['author', 'event']))
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('stop_light_color')
                    ->label('Color')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('occurred_at')
                    ->label('Date and Time')
                    ->dateTime(timezone: (string) config('app.display_timezone', config('app.timezone')))
                    ->sortable(),
                TextColumn::make('event.name')
                    ->label('Event')
                    ->placeholder('No event selected')
                    ->searchable(),
                TextColumn::make('author.full_name')
                    ->label('Teacher')
                    ->placeholder('Deleted user'),
                TextColumn::make('note')
                    ->wrap()
                    ->lineClamp(3)
                    ->searchable(),
                TextColumn::make('recipient_count')
                    ->label('Recipients')
                    ->state(fn (StudentCommunication $record): int => count($record->recipient_emails)),
                TextColumn::make('queued_at')
                    ->label('Queued')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(StudentCommunicationType::class),
                SelectFilter::make('stop_light_color')
                    ->label('Stop Light Color')
                    ->options(StopLightColor::class),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ], RecordActionsPosition::BeforeCells);
    }
}
