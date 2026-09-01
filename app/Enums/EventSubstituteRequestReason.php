<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventSubstituteRequestReason: string implements HasLabel
{
    case Sick = 'sick';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sick => 'Sick',
            self::Other => 'Other',
        };
    }
}
