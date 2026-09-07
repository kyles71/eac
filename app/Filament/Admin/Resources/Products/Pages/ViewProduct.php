<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Filament\Admin\Resources\Products\ProductResource;
use App\Models\Gear;
use App\Models\Product;
use App\Services\GearPurchaseReportService;
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
