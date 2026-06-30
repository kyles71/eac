<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ManagedBanners\Pages;

use App\Filament\Admin\Resources\ManagedBanners\ManagedBannerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditManagedBanner extends EditRecord
{
    public static bool $formActionsAreSticky = true;

    protected static string $resource = ManagedBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
