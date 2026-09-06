<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InstallmentPaymentAttemptOrigin: string implements HasLabel
{
    case Scheduled = 'Scheduled';
    case Customer = 'Customer';
    case Administrator = 'Administrator';

    public function getLabel(): string
    {
        return $this->value;
    }
}
