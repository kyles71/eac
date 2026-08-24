<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gear\Pages;

use App\Filament\Admin\Resources\Gear\GearResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListGear extends ListRecords
{
    protected static string $resource = GearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
