<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCardTypes\Pages;

use App\Filament\Admin\Resources\GiftCardTypes\GiftCardTypeResource;
use Filament\Resources\Pages\EditRecord;

final class EditGiftCardType extends EditRecord
{
    protected static string $resource = GiftCardTypeResource::class;
}
