<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringPrivateLessons\Pages;

use App\Filament\Admin\Resources\RecurringPrivateLessons\RecurringPrivateLessonResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewRecurringPrivateLesson extends ViewRecord
{
    protected static string $resource = RecurringPrivateLessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
