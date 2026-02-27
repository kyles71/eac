<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentPlanFrequency: string implements HasLabel
{
    case Weekly = 'Weekly';
    case Biweekly = 'Biweekly';
    case Monthly = 'Monthly';

    public function getLabel(): string
    {
        return $this->value;
    }

    /**
     * Get the number of days between installments for this frequency.
     */
    public function intervalDays(): int
    {
        return match ($this) {
            self::Weekly => 7,
            self::Biweekly => 14,
            self::Monthly => 30,
        };
    }
}
