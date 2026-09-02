<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Tables;

use App\Enums\EventSubstituteCoverageStatus;
use App\Filament\Actions\CancelEventAction;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Models\Event;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Event $record): ?string => EventResource::canView($record)
                ? EventResource::getUrl('view', ['record' => $record])
                : null)
            ->columns([
                TextColumn::make('name')
                    ->icon(fn (Event $record): ?Heroicon => $record->activeSubstituteCoverages()
                        ->whereNotNull('substitute_teacher_id')->exists()
                        ? Heroicon::OutlinedUser
                        : null)
                    ->iconColor('success')
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
                TextColumn::make('teachers.fullName')
                    ->label('Teachers')
                    ->listWithLineBreaks()
                    ->searchable(['first_name', 'last_name'])
                    ->toggleable(),
                TextColumn::make('substitute_coverage_status')
                    ->label('Substitute')
                    ->state(fn (Event $record): string => $record->substituteCoverageLabel())
                    ->badge()
                    ->color(fn (Event $record): string => $record->substituteCoverageStatus()->getColor())
                    ->toggleable(),
                TextColumn::make('substituteTeachers.fullName')
                    ->label('Confirmed Substitutes')
                    ->listWithLineBreaks()
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
                SelectFilter::make('substitute_coverage')
                    ->label('Substitute Coverage')
                    ->multiple()
                    ->options(EventSubstituteCoverageStatus::class)
                    ->query(fn (Builder $query, array $data): Builder => Event::applySubstituteCoverageStatusesConstraint(
                        $query,
                        is_array($data['values'] ?? null) ? $data['values'] : [],
                    )),
            ])
            ->recordActions([
                ActionGroup::make([
                    CancelEventAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
