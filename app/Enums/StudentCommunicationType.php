<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StudentCommunicationType: string implements HasColor, HasLabel
{
    case FirstAid = 'first_aid';
    case StopLight = 'stop_light';

    public function getLabel(): string
    {
        return match ($this) {
            self::FirstAid => 'First Aid',
            self::StopLight => 'Stop Light',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FirstAid => 'info',
            self::StopLight => 'warning',
        };
    }

    public function emailTypeKey(): string
    {
        return match ($this) {
            self::FirstAid => 'student-first-aid-note',
            self::StopLight => 'student-stop-light-message',
        };
    }
}
