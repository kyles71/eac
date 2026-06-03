<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Pages;

use App\Filament\Admin\Resources\Courses\CourseResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewCourse extends ViewRecord
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('attendance')
                ->label('Attendance')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->url(fn (): string => CourseResource::getUrl('attendance', ['record' => $this->getRecord()])),
            EditAction::make(),
        ];
    }
}
