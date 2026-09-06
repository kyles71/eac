<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InstallmentPaymentAttemptStatus: string implements HasColor, HasLabel
{
    case Pending = 'Pending';
    case RequiresPaymentMethod = 'RequiresPaymentMethod';
    case RequiresAction = 'RequiresAction';
    case Processing = 'Processing';
    case Succeeded = 'Succeeded';
    case Failed = 'Failed';
    case Cancelled = 'Cancelled';

    public function isActive(): bool
    {
        return in_array($this, [
            self::Pending,
            self::RequiresPaymentMethod,
            self::RequiresAction,
            self::Processing,
        ], true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::RequiresPaymentMethod => 'Needs payment method',
            self::RequiresAction => 'Needs customer action',
            default => $this->value,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
            default => 'warning',
        };
    }
}
