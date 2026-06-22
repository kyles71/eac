<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CalendarAccess: string implements HasLabel
{
    case Public = 'public';

    case Restricted = 'restricted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Restricted => 'Restricted',
        };
    }
}
