<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderItemStatus: string implements HasLabel
{
    case Pending = 'Pending';
    case Fulfilled = 'Fulfilled';

    public function getLabel(): string
    {
        return $this->value;
    }
}
