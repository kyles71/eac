<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CreditGrants\Pages;

use App\Filament\Admin\Resources\CreditGrants\CreditGrantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;

final class ListCreditGrants extends ListRecords
{
    protected static string $resource = CreditGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<class-string<Widget>> */
    protected function getHeaderWidgets(): array
    {
        return CreditGrantResource::getWidgets();
    }
}
