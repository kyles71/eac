<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AttendanceStatus: string implements HasLabel
{
    case Present = 'present';
    case Late = 'late';
    case ExcusedAbsence = 'excused_absence';
    case UnexcusedAbsence = 'unexcused_absence';

    public function getLabel(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::ExcusedAbsence => 'Excused absence',
            self::UnexcusedAbsence => 'Unexcused absence',
        };
    }
}
