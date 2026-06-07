<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MedicalWaiverStatus: string implements HasColor, HasLabel
{
    case OnFile = 'On File';
    case Expired = 'Expired';
    case Missing = 'Missing';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OnFile => 'success',
            self::Expired => 'danger',
            self::Missing => 'warning',
        };
    }
}
