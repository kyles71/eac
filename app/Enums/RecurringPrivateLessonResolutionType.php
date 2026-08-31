<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RecurringPrivateLessonResolutionType: string implements HasLabel
{
    case Credit = 'Credit';
    case Refund = 'Refund';

    public function getLabel(): string
    {
        return $this->value;
    }
}
