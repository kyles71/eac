<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Tables;

use App\Enums\EventSubstituteRequestStatus;
use App\Filament\Actions\CancelEventAction;
use App\Models\Event;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('substitute_coverage_status')
                    ->label('Substitute')
                    ->state(fn (Event $record) => $record->substituteCoverageStatus())
                    ->badge()
                    ->toggleable(),
                TextColumn::make('substituteTeacher.fullName')
                    ->label('Confirmed Substitute')
                    ->searchable(['first_name', 'last_name'])
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Filter::make('needs_substitute')
                    ->label('Needs Substitute')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('substitute_needed_at')
                        ->whereNull('substitute_teacher_id')
                        ->whereDoesntHave('substituteRequests', fn (Builder $query): Builder => $query
                            ->where('status', EventSubstituteRequestStatus::Pending))),
                Filter::make('pending_substitute')
                    ->label('Awaiting Substitute Response')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereHas('substituteRequests', fn (Builder $query): Builder => $query
                            ->where('status', EventSubstituteRequestStatus::Pending))),
                Filter::make('confirmed_substitute')
                    ->label('Confirmed Substitute')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('substitute_teacher_id')),
                Filter::make('substitute_release_requested')
                    ->label('Substitute Release Requested')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereHas('substituteRequests', fn (Builder $query): Builder => $query
                            ->where('status', EventSubstituteRequestStatus::Accepted)
                            ->whereNotNull('release_requested_at'))),
            ])
            ->recordActions([
                ActionGroup::make([
                    CancelEventAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
