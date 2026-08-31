<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RecurringPrivateLessonCoverageStatus: string implements HasLabel
{
    case Active = 'Active';
    case Credited = 'Credited';
    case Refunded = 'Refunded';

    public function getLabel(): string
    {
        return $this->value;
    }
}
