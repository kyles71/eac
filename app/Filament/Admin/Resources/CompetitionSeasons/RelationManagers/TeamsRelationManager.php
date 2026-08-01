<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionSeasons\RelationManagers;

use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use App\Filament\Admin\Resources\CompetitionTeams\Schemas\CompetitionTeamForm;
use App\Models\CompetitionSeason;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

final class TeamsRelationManager extends RelationManager
{
    protected static string $relationship = 'teams';

    protected static ?string $relatedResource = CompetitionTeamResource::class;

    public function isReadOnly(): bool
    {
        $season = $this->getOwnerRecord();

        return ! $season instanceof CompetitionSeason || $season->hasEnded();
    }

    public function table(Table $table): Table
    {
        $seasonId = (int) $this->getOwnerRecord()->getKey();

        return $table
            ->headerActions([
                CreateAction::make()
                    ->schema([
                        CompetitionTeamForm::nameField($seasonId),
                    ])
                    ->modal()
                    ->slideOver(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->schema([
                            CompetitionTeamForm::nameField($seasonId),
                        ])
                        ->modal()
                        ->slideOver(),
                ]),
            ]);
    }
}
