<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StopLightColor: string implements HasColor, HasLabel
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';

    public function getLabel(): string
    {
        return match ($this) {
            self::Green => 'Green',
            self::Yellow => 'Yellow',
            self::Red => 'Red',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Green => 'success',
            self::Yellow => 'warning',
            self::Red => 'danger',
        };
    }
}
