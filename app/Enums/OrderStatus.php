<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case Pending = 'Pending';
    case Completed = 'Completed';
    case Failed = 'Failed';
    case Refunded = 'Refunded';

    public function getLabel(): string
    {
        return $this->value;
    }
}
