<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductAvailabilityStatus: string implements HasColor, HasLabel
{
    case Available = 'available';
    case EarlyAccess = 'early_access';
    case Draft = 'draft';
    case InvalidPrice = 'invalid_price';
    case Scheduled = 'scheduled';
    case Expired = 'expired';
    case EligibilityRequired = 'eligibility_required';

    /**
     * @return array<string, string>
     */
    public static function adminOptions(): array
    {
        return collect(self::cases())
            ->reject(fn (self $status): bool => $status === self::EligibilityRequired)
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->getLabel()])
            ->all();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::EarlyAccess => 'Early Access',
            self::Draft => 'Draft',
            self::InvalidPrice => 'Invalid Price',
            self::Scheduled => 'Scheduled',
            self::Expired => 'Expired',
            self::EligibilityRequired => 'Eligibility Required',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::EarlyAccess => 'info',
            self::Scheduled => 'warning',
            self::Draft, self::InvalidPrice, self::Expired, self::EligibilityRequired => 'danger',
        };
    }

    public function isPurchasable(): bool
    {
        return in_array($this, [self::Available, self::EarlyAccess], true);
    }

    public function message(?string $productName = null): string
    {
        $subject = $productName === null ? 'This product' : "\"{$productName}\"";

        return match ($this) {
            self::Available, self::EarlyAccess => "{$subject} is available for purchase.",
            self::Draft => "{$subject} is not available for purchase.",
            self::InvalidPrice => "{$subject} does not have a valid price.",
            self::Scheduled => "{$subject} is not available yet.",
            self::Expired => "{$subject} is no longer available for purchase.",
            self::EligibilityRequired => "{$subject} is limited to its configured purchase audience.",
        };
    }
}
