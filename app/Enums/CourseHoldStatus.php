<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CourseHoldStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case PartiallyPurchased = 'partially_purchased';
    case Purchased = 'purchased';
    case Expired = 'expired';
    case Released = 'released';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::PartiallyPurchased => 'Partially Purchased',
            self::Purchased => 'Purchased',
            self::Expired => 'Expired',
            self::Released => 'Released',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::PartiallyPurchased => 'warning',
            self::Purchased => 'info',
            self::Expired, self::Released => 'gray',
        };
    }
}
