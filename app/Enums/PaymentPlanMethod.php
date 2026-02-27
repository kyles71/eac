<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentPlanMethod: string implements HasLabel
{
    case AutoCharge = 'Auto Charge';
    case ManualInvoice = 'Manual Invoice';

    public function getLabel(): string
    {
        return $this->value;
    }
}
