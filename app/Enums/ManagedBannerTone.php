<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ManagedBannerTone: string implements HasColor, HasLabel
{
    case Gray = 'gray';
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Primary = 'primary';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gray => 'Neutral',
            self::Info => 'Information',
            self::Success => 'Success',
            self::Warning => 'Warning',
            self::Danger => 'Danger',
            self::Primary => 'Primary',
        };
    }

    public function getColor(): string
    {
        return $this->value;
    }

    public function defaultIcon(): Heroicon
    {
        return match ($this) {
            self::Success => Heroicon::OutlinedCheckCircle,
            self::Warning => Heroicon::OutlinedExclamationTriangle,
            self::Danger => Heroicon::OutlinedXCircle,
            self::Primary => Heroicon::OutlinedMegaphone,
            self::Gray, self::Info => Heroicon::OutlinedInformationCircle,
        };
    }
}
