<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gear\Pages;

use App\Filament\Admin\Resources\Gear\GearResource;
use App\Services\GearPurchaseReportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListGear extends ListRecords
{
    protected static string $resource = GearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPurchaseReport')
                ->label('Download Purchase Report')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => app(GearPurchaseReportService::class)->downloadAll()),
            CreateAction::make(),
        ];
    }
}
