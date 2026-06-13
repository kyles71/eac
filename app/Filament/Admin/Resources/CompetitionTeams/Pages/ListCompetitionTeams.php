<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\Pages;

use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCompetitionTeams extends ListRecords
{
    protected static string $resource = CompetitionTeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
