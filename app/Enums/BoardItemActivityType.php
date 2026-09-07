<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BoardItemActivityType: string implements HasColor, HasLabel
{
    case Created = 'created';
    case StageChanged = 'stage_changed';
    case AssigneesChanged = 'assignees_changed';
    case Archived = 'archived';
    case Restored = 'restored';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::StageChanged => 'Stage changed',
            self::AssigneesChanged => 'Assignees changed',
            self::Archived => 'Archived',
            self::Restored => 'Restored',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Created => 'success',
            self::StageChanged => 'info',
            self::AssigneesChanged => 'warning',
            self::Archived => 'gray',
            self::Restored => 'success',
        };
    }
}
