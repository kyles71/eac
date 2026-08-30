<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\AcademicTerms\Pages;

use App\Filament\Clusters\Settings\Resources\AcademicTerms\AcademicTermResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ListAcademicTerms extends ManageRecords
{
    protected static string $resource = AcademicTermResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => AcademicTermResource::prepareFormData($data)),
        ];
    }
}
