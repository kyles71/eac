<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\Pages;

use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use App\Filament\Admin\Resources\CompetitionTeams\Schemas\CompetitionTeamForm;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCompetitionTeam extends ViewRecord
{
    protected static string $resource = CompetitionTeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->schema(CompetitionTeamForm::components())
                ->modal()
                ->slideOver(),
        ];
    }
}
