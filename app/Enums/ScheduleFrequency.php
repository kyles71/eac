<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ScheduleFrequency: string implements HasLabel
{
    case DAILY = 'Daily';
    case WEEKLY = 'Weekly';
    case BIWEEKLY = 'Bi-weekly';
    case MONTHLY = 'Monthly';

    public function getLabel(): ?string
    {
        return $this->value;
    }
}
