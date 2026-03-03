<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GiftCardTypes\Pages;

use App\Filament\Admin\Resources\GiftCardTypes\GiftCardTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListGiftCardTypes extends ListRecords
{
    protected static string $resource = GiftCardTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
