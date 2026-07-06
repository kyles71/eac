<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FormPurpose: string implements HasLabel
{
    case MedicalWaiver = 'medical_waiver';
    case ShowcaseParticipation = 'showcase_participation';
    case Generic = 'generic';

    public function getLabel(): string
    {
        return match ($this) {
            self::MedicalWaiver => 'Medical Waiver',
            self::ShowcaseParticipation => 'Showcase Participation',
            self::Generic => 'Generic',
        };
    }
}
