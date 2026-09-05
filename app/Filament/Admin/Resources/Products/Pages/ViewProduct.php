<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Filament\Admin\Resources\Products\ProductResource;
use App\Models\Gear;
use App\Models\Product;
use App\Services\GearPurchaseReportService;
use App\Services\ProductPurchaseReportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewPurchaseStatus')
                ->label('View Purchase Status')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->visible(fn (Product $record): bool => $record->is_purchase_required)
                ->url(fn (Product $record): string => ProductResource::getUrl('purchase-status', ['record' => $record])),
            Action::make('downloadRequirementReport')
                ->label('Download Purchase Status')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->visible(fn (Product $record): bool => $record->is_purchase_required)
                ->action(fn (Product $record) => app(ProductPurchaseReportService::class)->download($record)),
            Action::make('downloadPurchaseReport')
                ->label('Download Purchase Report')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (Product $record): bool => $record->productable_type === (new Gear)->getMorphClass())
                ->action(fn (Product $record) => app(GearPurchaseReportService::class)->downloadForProduct($record)),
            EditAction::make()
                ->mutateDataUsing(fn (array $data): array => ProductResource::normalizePricingData($data)),
        ];
    }
}
