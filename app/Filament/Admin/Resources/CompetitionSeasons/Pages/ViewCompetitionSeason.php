<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionSeasons\Pages;

use App\Filament\Admin\Resources\CompetitionSeasons\CompetitionSeasonResource;
use App\Filament\Admin\Resources\CompetitionSeasons\Schemas\CompetitionSeasonForm;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCompetitionSeason extends ViewRecord
{
    protected static string $resource = CompetitionSeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->schema(CompetitionSeasonForm::components())
                ->modal()
                ->slideOver(),
        ];
    }
}
