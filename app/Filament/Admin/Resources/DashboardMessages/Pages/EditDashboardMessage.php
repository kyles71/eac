<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DashboardMessages\Pages;

use App\Filament\Admin\Resources\DashboardMessages\DashboardMessageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditDashboardMessage extends EditRecord
{
    protected static string $resource = DashboardMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
