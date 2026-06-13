<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\Pages;

use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCompetitionTeam extends CreateRecord
{
    protected static string $resource = CompetitionTeamResource::class;
}
