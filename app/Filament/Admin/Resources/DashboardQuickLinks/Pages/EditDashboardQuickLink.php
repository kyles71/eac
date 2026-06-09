<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardQuickLinks\Pages;

use App\Filament\Admin\Resources\DashboardQuickLinks\DashboardQuickLinkResource;
use App\Services\DashboardQuickLinkDestinationService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditDashboardQuickLink extends EditRecord
{
    protected static string $resource = DashboardQuickLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! self::isExternal($data['destination'] ?? null)) {
            $data['external_url'] = null;
        }

        return $data;
    }

    private static function isExternal(mixed $destination): bool
    {
        return app(DashboardQuickLinkDestinationService::class)->isExternal($destination);
    }
}
