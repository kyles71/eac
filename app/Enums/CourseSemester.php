<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CourseSemester: string implements HasColor, HasLabel
{
    case WinterSpring = 'Winter-Spring';
    case Summer = 'Summer';
    case Fall = 'Fall';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::WinterSpring => 'info',
            self::Summer => 'warning',
            self::Fall => 'success',
        };
    }
}
