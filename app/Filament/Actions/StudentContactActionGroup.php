<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\StudentCommunicationType;
use App\Models\Event;
use App\Models\Student;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;

final readonly class StudentContactActionGroup
{
    public static function make(Student|Closure $student, Event|Closure|null $event = null): ActionGroup
    {
        return ActionGroup::make(self::actions($student, $event))
            ->label('Contact Student')
            ->icon(Heroicon::OutlinedEnvelope);
    }

    /**
     * @return array<Action>
     */
    public static function actions(Student|Closure $student, Event|Closure|null $event = null): array
    {
        return [
            SendEmailAction::make()
                ->label('Custom Email')
                ->forStudent($student, $event),
            SendStudentCommunicationAction::make('sendFirstAidNote')
                ->student($student)
                ->event($event)
                ->communicationType(StudentCommunicationType::FirstAid),
            SendStudentCommunicationAction::make('sendStopLightMessage')
                ->student($student)
                ->event($event)
                ->communicationType(StudentCommunicationType::StopLight),
        ];
    }
}
