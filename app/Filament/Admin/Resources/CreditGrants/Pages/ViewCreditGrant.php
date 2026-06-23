<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CreditGrants\Pages;

use App\Filament\Actions\RevokeCreditGrantAction;
use App\Filament\Admin\Resources\CreditGrants\CreditGrantResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCreditGrant extends ViewRecord
{
    protected static string $resource = CreditGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RevokeCreditGrantAction::make(),
        ];
    }
}
