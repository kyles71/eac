<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EventSubstituteCoverageStatus: string implements HasColor, HasLabel
{
    case NotNeeded = 'Not Needed';
    case NeedsSubstitute = 'Needs Substitute';
    case AwaitingResponse = 'Awaiting Response';
    case Confirmed = 'Confirmed';
    case ReplacementPending = 'Replacement Pending';
    case ReleaseRequested = 'Release Requested';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotNeeded => 'gray',
            self::NeedsSubstitute, self::ReleaseRequested => 'danger',
            self::AwaitingResponse, self::ReplacementPending => 'warning',
            self::Confirmed => 'success',
        };
    }
}
