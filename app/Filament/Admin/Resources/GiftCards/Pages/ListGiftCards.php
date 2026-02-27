<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCards\Pages;

use App\Filament\Admin\Resources\GiftCards\GiftCardResource;
use Filament\Resources\Pages\ListRecords;

final class ListGiftCards extends ListRecords
{
    protected static string $resource = GiftCardResource::class;
}
