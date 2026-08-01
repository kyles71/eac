<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\StudentCommunicationType;
use App\Models\Event;
use App\Models\Student;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;

final readonly class StudentContactActionGroup
{
    public static function make(Student|Closure $student, Event|Closure|null $event = null): ActionGroup
    {
        return ActionGroup::make([
            SendEmailAction::make()
                ->label('Custom Email')
                ->to($student),
            SendStudentCommunicationAction::make('sendFirstAidNote')
                ->student($student)
                ->event($event)
                ->communicationType(StudentCommunicationType::FirstAid),
            SendStudentCommunicationAction::make('sendStopLightMessage')
                ->student($student)
                ->event($event)
                ->communicationType(StudentCommunicationType::StopLight),
        ])
            ->label('Contact Student')
            ->icon(Heroicon::OutlinedEnvelope);
    }
}
