<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ScheduleFrequency: string implements HasLabel
{
    case Daily = 'Daily';
    case Weekly = 'Weekly';
    case Biweekly = 'Bi-weekly';
    case Monthly = 'Monthly';

    public function getLabel(): string
    {
        return $this->value;
    }
}
