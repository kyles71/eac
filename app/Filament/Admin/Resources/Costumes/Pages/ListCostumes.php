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
            Action::make('viewProductsNotOrdered')
                ->label('View Products Not Ordered')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->url(CostumeResource::getUrl('products-not-ordered')),
            Action::make('downloadProductsNotOrdered')
                ->label('Download Products Not Ordered')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => app(CostumePurchaseReportService::class)->downloadNotOrdered()),
            Action::make('downloadPurchaseReport')
                ->label('Download Purchase Report')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => app(CostumePurchaseReportService::class)->downloadAllPurchases()),
            CreateAction::make(),
        ];
    }
}
