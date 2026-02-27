<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCards\Pages;

use App\Filament\Admin\Resources\GiftCards\GiftCardResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateGiftCard extends CreateRecord
{
    protected static string $resource = GiftCardResource::class;
}
