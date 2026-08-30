<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SavedReportViewVisibility: string implements HasLabel
{
    case Private = 'private';
    case Template = 'template';

    public function getLabel(): string
    {
        return match ($this) {
            self::Private => 'Private view',
            self::Template => 'Staff template',
        };
    }
}
