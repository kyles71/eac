<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionSeasons\Pages;

use App\Filament\Admin\Resources\CompetitionSeasons\CompetitionSeasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCompetitionSeasons extends ListRecords
{
    protected static string $resource = CompetitionSeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
