<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardQuickLinks\Pages;

use App\Filament\Admin\Resources\DashboardQuickLinks\DashboardQuickLinkResource;
use App\Models\DashboardQuickLink;
use App\Services\DashboardQuickLinkDestinationService;
use Filament\Resources\Pages\CreateRecord;

final class CreateDashboardQuickLink extends CreateRecord
{
    protected static string $resource = DashboardQuickLinkResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! self::isExternal($data['destination'] ?? null)) {
            $data['external_url'] = null;
        }

        $data['sort_order'] = ((int) DashboardQuickLink::query()->max('sort_order')) + 1;

        return $data;
    }

    private static function isExternal(mixed $destination): bool
    {
        return app(DashboardQuickLinkDestinationService::class)->isExternal($destination);
    }
}
