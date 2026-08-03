<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StudentNoteType: string implements HasColor, HasLabel
{
    case Attendance = 'attendance';
    case Staff = 'staff_note';
    case FirstAid = 'first_aid';
    case StopLight = 'stop_light';

    public static function fromCommunicationType(StudentCommunicationType $type): self
    {
        return match ($type) {
            StudentCommunicationType::FirstAid => self::FirstAid,
            StudentCommunicationType::StopLight => self::StopLight,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Attendance => 'Attendance Note',
            self::Staff => 'Staff Note',
            self::FirstAid => 'First Aid',
            self::StopLight => 'Stop Light',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Attendance => 'gray',
            self::Staff => 'primary',
            self::FirstAid => 'info',
            self::StopLight => 'warning',
        };
    }
}
