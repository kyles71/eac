<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProductQuestionType: string implements HasLabel
{
    case Text = 'Text';
    case Select = 'Select';

    public function getLabel(): string
    {
        return $this->value;
    }
}
