<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DashboardAudience: string implements HasColor, HasLabel
{
    case Eac = 'EAC';
    case Semester = 'Semester';
    case Teacher = 'Teacher';
    case Owner = 'Owner';

    public function getLabel(): string
    {
        return "{$this->value} Audience";
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Eac => 'gray',
            self::Semester => 'info',
            self::Teacher => 'warning',
            self::Owner => 'success',
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::Owner => 1,
            self::Teacher => 2,
            self::Semester => 3,
            self::Eac => 4,
        };
    }
}
