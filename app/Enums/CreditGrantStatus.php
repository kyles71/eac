<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CreditGrantStatus: string implements HasColor, HasLabel
{
    case Active = 'Active';
    case Depleted = 'Depleted';
    case Expired = 'Expired';
    case Revoked = 'Revoked';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Depleted => 'gray',
            self::Expired => 'warning',
            self::Revoked => 'danger',
        };
    }
}
