<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CourseProgramType: string implements HasLabel
{
    case Standard = 'Standard';
    case Competition = 'Competition';

    public function getLabel(): string
    {
        return $this->value;
    }
}
