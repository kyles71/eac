<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardMessages\Pages;

use App\Filament\Admin\Resources\DashboardMessages\DashboardMessageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDashboardMessages extends ListRecords
{
    protected static string $resource = DashboardMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
