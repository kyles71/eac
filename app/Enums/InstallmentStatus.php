<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InstallmentStatus: string implements HasLabel
{
    case Pending = 'Pending';
    case Paid = 'Paid';
    case Failed = 'Failed';
    case Overdue = 'Overdue';

    public function getLabel(): string
    {
        return $this->value;
    }
}
