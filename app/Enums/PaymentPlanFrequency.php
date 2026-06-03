<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentPlanFrequency: string implements HasLabel
{
    case Daily = 'Daily';
    case Weekly = 'Weekly';
    case Biweekly = 'Biweekly';
    case Monthly = 'Monthly';

    /**
     * @return array<string, string>
     */
    public static function optionsForEnvironment(): array
    {
        return collect(self::cases())
            ->reject(fn (self $frequency): bool => app()->isProduction() && $frequency === self::Daily)
            ->mapWithKeys(fn (self $frequency): array => [$frequency->value => $frequency->getLabel()])
            ->all();
    }

    public function getLabel(): string
    {
        return $this->value;
    }

    /**
     * Get the number of days between installments for this frequency.
     */
    public function intervalDays(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 7,
            self::Biweekly => 14,
            self::Monthly => 30,
        };
    }
}
