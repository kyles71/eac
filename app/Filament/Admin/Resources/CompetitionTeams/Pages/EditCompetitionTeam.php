<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\Pages;

use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCompetitionTeam extends EditRecord
{
    protected static string $resource = CompetitionTeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
