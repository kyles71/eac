<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ManagedBanners\Pages;

use App\Filament\Admin\Resources\ManagedBanners\ManagedBannerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListManagedBanners extends ListRecords
{
    protected static string $resource = ManagedBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
