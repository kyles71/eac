<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FirstAidType: string implements HasLabel
{
    case FirstAid = 'first_aid';
    case Injury = 'injury';
    case Medicine = 'medicine';

    public function getLabel(): string
    {
        return match ($this) {
            self::FirstAid => 'FIRST AID',
            self::Injury => 'INJURY',
            self::Medicine => 'MEDICINE',
        };
    }
}
