<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionTeams\Pages;

use App\Filament\Actions\SendEmailAction;
use App\Filament\Admin\Resources\CompetitionTeams\CompetitionTeamResource;
use App\Filament\Admin\Resources\CompetitionTeams\Schemas\CompetitionTeamForm;
use App\Models\CompetitionTeam;
use App\Services\CompetitionEmailRecipientsService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewCompetitionTeam extends ViewRecord
{
    protected static string $resource = CompetitionTeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SendEmailAction::make()
                ->label('Email Team')
                ->to(fn (): array => app(CompetitionEmailRecipientsService::class)->forTeam($this->team())),
            EditAction::make()
                ->schema(CompetitionTeamForm::components())
                ->modal()
                ->slideOver(),
        ];
    }

    private function team(): CompetitionTeam
    {
        $record = $this->getRecord();

        if (! $record instanceof CompetitionTeam) {
            throw new LogicException('The competition team record is unavailable.');
        }

        return $record;
    }
}
