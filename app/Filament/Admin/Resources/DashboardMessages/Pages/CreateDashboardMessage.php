<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardMessages\Pages;

use App\Filament\Admin\Resources\DashboardMessages\DashboardMessageResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateDashboardMessage extends CreateRecord
{
    protected static string $resource = DashboardMessageResource::class;
}
