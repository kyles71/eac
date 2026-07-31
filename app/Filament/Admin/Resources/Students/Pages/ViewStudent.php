<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Students\Pages;

use App\Filament\Actions\SendEmailAction;
use App\Filament\Admin\Resources\Students\StudentResource;
use App\Models\Student;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SendEmailAction::make()
                ->to(fn (): array => [$this->student()]),
            EditAction::make(),
        ];
    }

    private function student(): Student
    {
        $record = $this->getRecord();

        if (! $record instanceof Student) {
            throw new LogicException('The student record is unavailable.');
        }

        return $record;
    }
}
