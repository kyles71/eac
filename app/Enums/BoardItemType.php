<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BoardItemType: string implements HasColor, HasLabel
{
    case Bug = 'bug';
    case FeatureRequest = 'feature_request';
    case Idea = 'idea';
    case Task = 'task';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::FeatureRequest => 'Feature',
            self::Idea => 'Idea',
            self::Task => 'Task',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bug => 'danger',
            self::FeatureRequest => 'info',
            self::Idea => 'warning',
            self::Task => 'gray',
        };
    }
}
