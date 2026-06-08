<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\Holidays\Pages;

use App\Filament\Clusters\Settings\Resources\Holidays\HolidayResource;
use App\Models\Holiday;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

final class ManageHolidays extends ManageRecords
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->successNotification(fn (Holiday $record): Notification => HolidayResource::saveNotification($record, 'created')),
        ];
    }
}
