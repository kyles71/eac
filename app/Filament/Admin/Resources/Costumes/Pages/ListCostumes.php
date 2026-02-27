<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Pages;

use App\Filament\Admin\Resources\Costumes\CostumeResource;
use Filament\Resources\Pages\ListRecords;

final class ListCostumes extends ListRecords
{
    protected static string $resource = CostumeResource::class;
}
