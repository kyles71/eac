<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Pages;

use App\Filament\Admin\Resources\Costumes\CostumeResource;
use App\Services\CostumePurchaseReportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListCostumes extends ListRecords
{
    protected static string $resource = CostumeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPurchaseReport')
                ->label('Download Purchase Report')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => app(CostumePurchaseReportService::class)->downloadAllPurchases()),
            CreateAction::make(),
        ];
    }
}
