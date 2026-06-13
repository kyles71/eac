<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionSeasons\Pages;

use App\Filament\Admin\Resources\CompetitionSeasons\CompetitionSeasonResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCompetitionSeason extends EditRecord
{
    protected static string $resource = CompetitionSeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
