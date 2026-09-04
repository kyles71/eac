<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BoardMemberRole: string implements HasColor, HasLabel
{
    case Viewer = 'viewer';
    case Contributor = 'contributor';
    case Manager = 'manager';

    public function getLabel(): string
    {
        return match ($this) {
            self::Viewer => 'Viewer',
            self::Contributor => 'Contributor',
            self::Manager => 'Manager',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Viewer => 'gray',
            self::Contributor => 'info',
            self::Manager => 'success',
        };
    }

    public function canContribute(): bool
    {
        return $this !== self::Viewer;
    }

    public function canManage(): bool
    {
        return $this === self::Manager;
    }
}
