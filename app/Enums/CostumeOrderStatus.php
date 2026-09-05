<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CostumeOrderStatus: string implements HasColor, HasLabel
{
    case NotOrdered = 'Not Ordered';
    case Partial = 'Partial';
    case Complete = 'Complete';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotOrdered => 'danger',
            self::Partial => 'warning',
            self::Complete => 'success',
        };
    }
}
