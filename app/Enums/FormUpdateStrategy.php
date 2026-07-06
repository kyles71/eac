<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormUpdateStrategy: string implements HasLabel
{
    case Revision = 'revision';
    case InPlace = 'in_place';

    public function getLabel(): string
    {
        return match ($this) {
            self::Revision => 'Create a revision',
            self::InPlace => 'Replace the previous response',
        };
    }
}
