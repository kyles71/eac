<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel, HasColor
{
    case Pending = 'Pending';
    case Completed = 'Completed';
    case Failed = 'Failed';
    case Refunded = 'Refunded';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::Completed => 'success',
            self::Pending => 'warning',
            self::Failed => 'danger',
            self::Refunded => 'gray',
        };
    }
}
