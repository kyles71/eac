<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DashboardAudience: string implements HasColor, HasLabel
{
    case Eac = 'EAC';
    case Semester = 'Semester';
    case CompTeam = 'Comp Team';
    case Teacher = 'Teacher';
    case Owner = 'Owner';

    public function getLabel(): string
    {
        return match ($this) {
            self::Eac => 'EAC Audience',
            self::Semester => 'Semester Audience',
            self::CompTeam => 'Comp Team Audience',
            self::Teacher => 'Teacher Audience',
            self::Owner => 'Owner Audience',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Eac => 'gray',
            self::Semester => 'info',
            self::CompTeam => 'primary',
            self::Teacher => 'warning',
            self::Owner => 'success',
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::Owner => 10,
            self::Teacher => 20,
            self::CompTeam => 30,
            self::Semester => 40,
            self::Eac => 50,
        };
    }
}
