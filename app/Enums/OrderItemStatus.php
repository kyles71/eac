<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderItemStatus: string implements HasColor, HasLabel
{
    case Pending = 'Pending';
    case PartiallyFulfilled = 'Partially Fulfilled';
    case Fulfilled = 'Fulfilled';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::PartiallyFulfilled => 'info',
            self::Fulfilled => 'success',
        };
    }
}
