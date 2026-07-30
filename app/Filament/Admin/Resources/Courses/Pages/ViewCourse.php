<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Courses\Pages;

use App\Filament\Admin\Resources\Courses\CourseResource;
use App\Models\Course;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class ViewCourse extends ViewRecord
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('attendance')
                ->label('Attendance')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->visible(fn (): bool => Gate::allows('viewAttendance', $this->course()))
                ->url(fn (): string => CourseResource::getUrl('attendance', ['record' => $this->getRecord()])),
            EditAction::make(),
        ];
    }

    private function course(): Course
    {
        $record = $this->getRecord();

        if (! $record instanceof Course) {
            throw new LogicException('The course record is unavailable.');
        }

        return $record;
    }
}
