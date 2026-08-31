<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RecurringPrivateLessonStatus: string implements HasColor, HasLabel
{
    case Active = 'Active';
    case Completed = 'Completed';
    case Cancelled = 'Cancelled';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Completed => 'info',
            self::Cancelled => 'gray',
        };
    }
}
