<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CompetitionSeasons\Pages;

use App\Filament\Admin\Resources\CompetitionSeasons\CompetitionSeasonResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCompetitionSeason extends CreateRecord
{
    protected static string $resource = CompetitionSeasonResource::class;
}
