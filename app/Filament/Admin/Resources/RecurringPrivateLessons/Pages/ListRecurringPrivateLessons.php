<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringPrivateLessons\Pages;

use App\Filament\Admin\Resources\RecurringPrivateLessons\RecurringPrivateLessonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListRecurringPrivateLessons extends ListRecords
{
    protected static string $resource = RecurringPrivateLessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
