<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardQuickLinks\Pages;

use App\Filament\Admin\Resources\DashboardQuickLinks\DashboardQuickLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDashboardQuickLinks extends ListRecords
{
    protected static string $resource = DashboardQuickLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
