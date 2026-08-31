<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RecurringPrivateLessonChargeStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'Scheduled';
    case Billed = 'Billed';
    case Paid = 'Paid';
    case Cancelled = 'Cancelled';
    case Credited = 'Credited';
    case Refunded = 'Refunded';

    public function isPayable(): bool
    {
        return $this === self::Billed;
    }

    public function isSatisfied(): bool
    {
        return $this === self::Paid;
    }

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'gray',
            self::Billed => 'warning',
            self::Paid => 'success',
            self::Credited, self::Refunded, self::Cancelled => 'gray',
        };
    }
}
