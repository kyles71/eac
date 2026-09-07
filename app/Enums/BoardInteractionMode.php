<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BoardInteractionMode: string implements HasColor, HasLabel
{
    case Moderated = 'moderated';
    case Collaborative = 'collaborative';

    public function getLabel(): string
    {
        return match ($this) {
            self::Moderated => 'Moderated',
            self::Collaborative => 'Collaborative',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Moderated => 'warning',
            self::Collaborative => 'success',
        };
    }
}
