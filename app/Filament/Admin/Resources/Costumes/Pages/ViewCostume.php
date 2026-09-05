<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Costumes\Pages;

use App\Filament\Actions\ManageProductListingAction;
use App\Filament\Admin\Resources\Costumes\CostumeResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Models\Costume;
use App\Services\CostumePurchaseReportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewCostume extends ViewRecord
{
    protected static string $resource = CostumeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ManageProductListingAction::make(),
            Action::make('viewPurchaseStatus')
                ->label('View Order Status')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->visible(fn (Costume $record): bool => $record->product?->is_purchase_required === true)
                ->url(fn (Costume $record): string => ProductResource::getUrl('purchase-status', ['record' => $record->product])),
            Action::make('downloadRequirementReport')
                ->label('Download Order Status')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->visible(fn (Costume $record): bool => $record->product?->is_purchase_required === true)
                ->action(fn (Costume $record) => app(CostumePurchaseReportService::class)->downloadRequirements($record)),
            Action::make('downloadPurchaseReport')
                ->label('Download Purchases')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (Costume $record) => app(CostumePurchaseReportService::class)->downloadPurchasesForCostume($record)),
            EditAction::make(),
        ];
    }
}
