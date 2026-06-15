<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\Tables;

use App\Filament\Actions\SendEmailAction;
use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use App\Models\CompetitionTeam;
use App\Services\CompetitionEmailRecipientsService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class CompetitionTeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('season.name')
                    ->label('Season')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (CompetitionTeam $record): string => $record->season->status())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Current' => 'success',
                        'Upcoming' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('students_count')
                    ->label('Students')
                    ->counts('students')
                    ->sortable(),
                TextColumn::make('staff_count')
                    ->label('Staff')
                    ->counts('staff')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('competition_season_id')
                    ->label('Season')
                    ->relationship('season', 'name'),
            ])
            ->recordActions([
                SendEmailAction::make()
                    ->to(fn (CompetitionTeam $record): array => app(CompetitionEmailRecipientsService::class)->forTeam($record)),
            ])
            ->recordUrl(fn (CompetitionTeam $record): string => CompetitionTeamResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->defaultSort('competition_season_id', 'desc');
    }
}
