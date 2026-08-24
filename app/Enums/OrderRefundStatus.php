<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderRefundStatus: string implements HasColor, HasLabel
{
    case Processing = 'Processing';
    case Pending = 'Pending';
    case Succeeded = 'Succeeded';
    case PartiallyFailed = 'Partially Failed';
    case Failed = 'Failed';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Processing, self::Pending => 'warning',
            self::Succeeded => 'success',
            self::PartiallyFailed, self::Failed => 'danger',
        };
    }
}
