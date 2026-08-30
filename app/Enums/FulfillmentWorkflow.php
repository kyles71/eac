<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FulfillmentWorkflow: string implements HasColor, HasLabel
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case ScheduledEvent = 'scheduled_event';

    /** @return array<string, string> */
    public static function configurableOptions(): array
    {
        return [
            self::Manual->value => self::Manual->getLabel(),
            self::ScheduledEvent->value => self::ScheduledEvent->getLabel(),
        ];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic',
            self::Manual => 'Manual',
            self::ScheduledEvent => 'Scheduled event',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Automatic => 'success',
            self::Manual => 'gray',
            self::ScheduledEvent => 'info',
        };
    }
}
