<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BoardTemplate: string implements HasLabel
{
    case ProductFeedback = 'product_feedback';
    case GeneralKanban = 'general_kanban';
    case Blank = 'blank';

    public function getLabel(): string
    {
        return match ($this) {
            self::ProductFeedback => 'Product Feedback',
            self::GeneralKanban => 'General Kanban',
            self::Blank => 'Custom board',
        };
    }
}
