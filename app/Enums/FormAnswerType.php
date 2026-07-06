<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormAnswerType: string implements HasLabel
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';

    public function getLabel(): string
    {
        return match ($this) {
            self::String => 'Short text',
            self::Text => 'Long text',
            self::Integer => 'Whole number',
            self::Decimal => 'Number',
            self::Boolean => 'Yes / No',
            self::Date => 'Date',
            self::DateTime => 'Date and time',
        };
    }
}
