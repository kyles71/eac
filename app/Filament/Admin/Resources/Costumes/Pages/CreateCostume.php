<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Pages;

use App\Filament\Admin\Resources\Costumes\CostumeResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCostume extends CreateRecord
{
    protected static string $resource = CostumeResource::class;
}
