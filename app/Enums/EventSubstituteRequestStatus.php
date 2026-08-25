<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EventSubstituteRequestStatus: string implements HasColor, HasLabel
{
    case Pending = 'Pending';
    case Accepted = 'Accepted';
    case Declined = 'Declined';
    case Withdrawn = 'Withdrawn';
    case Expired = 'Expired';
    case Replaced = 'Replaced';
    case Removed = 'Removed';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Accepted => 'success',
            self::Pending => 'warning',
            self::Declined, self::Expired => 'danger',
            self::Withdrawn, self::Replaced, self::Removed => 'gray',
        };
    }
}
