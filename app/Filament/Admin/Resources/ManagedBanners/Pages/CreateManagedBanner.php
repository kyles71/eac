<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ManagedBanners\Pages;

use App\Filament\Admin\Resources\ManagedBanners\ManagedBannerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateManagedBanner extends CreateRecord
{
    public static bool $formActionsAreSticky = true;

    protected static string $resource = ManagedBannerResource::class;
}
