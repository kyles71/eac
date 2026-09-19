<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InstallmentPaymentAttemptEmailStatus: string implements HasColor, HasLabel
{
    case Pending = 'Pending';
    case Processing = 'Processing';
    case Queued = 'Queued';
    case Skipped = 'Skipped';
    case Failed = 'Failed';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'success',
            self::Pending, self::Processing => 'warning',
            self::Skipped => 'gray',
            self::Failed => 'danger',
        };
    }
}
