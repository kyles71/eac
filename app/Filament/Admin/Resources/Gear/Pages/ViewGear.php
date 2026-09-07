<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gear\Pages;

use App\Filament\Admin\Resources\Gear\GearResource;
use App\Models\Gear;
use App\Services\GearPurchaseReportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewGear extends ViewRecord
{
    protected static string $resource = GearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPurchaseReport')
                ->label('Download Purchase Report')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (Gear $record) => app(GearPurchaseReportService::class)->downloadForGear($record)),
            EditAction::make(),
        ];
    }
}
