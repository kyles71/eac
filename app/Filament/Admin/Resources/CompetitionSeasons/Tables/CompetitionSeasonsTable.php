<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionSeasons\Tables;

use App\Filament\Actions\SendEmailAction;
use App\Filament\Admin\Resources\CompetitionSeasons\CompetitionSeasonResource;
use App\Models\CompetitionSeason;
use App\Services\CompetitionEmailRecipients;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CompetitionSeasonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('starts_on')
                    ->label('Starts On')
                    ->date()
                    ->sortable(),
                TextColumn::make('ends_on')
                    ->label('Ends On')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (CompetitionSeason $record): string => $record->status())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Current' => 'success',
                        'Upcoming' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('teams_count')
                    ->label('Teams')
                    ->counts('teams')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                SendEmailAction::make()
                    ->to(fn (CompetitionSeason $record): array => app(CompetitionEmailRecipients::class)->forSeason($record)),
            ])
            ->recordUrl(fn (CompetitionSeason $record): string => CompetitionSeasonResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ])
            ->defaultSort('starts_on', 'desc');
    }
}
