<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Calendars\Pages;

use App\Filament\Admin\Resources\Calendars\CalendarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCalendars extends ListRecords
{
    protected static string $resource = CalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
