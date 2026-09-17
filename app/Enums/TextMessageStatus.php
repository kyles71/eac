<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TextMessageStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted by provider',
            self::Unknown => 'Unknown — review in provider',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Accepted => 'success',
            self::Failed => 'danger',
            self::Unknown => 'warning',
            self::Sending => 'info',
            default => 'gray',
        };
    }
}
