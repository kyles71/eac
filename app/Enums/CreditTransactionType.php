<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CreditTransactionType: string implements HasLabel
{
    case GiftCardRedemption = 'GiftCardRedemption';
    case CheckoutDebit = 'CheckoutDebit';
    case Refund = 'Refund';
    case AdminAdjustment = 'AdminAdjustment';

    public function getLabel(): string
    {
        return match ($this) {
            self::GiftCardRedemption => 'Gift Card Redemption',
            self::CheckoutDebit => 'Checkout Debit',
            self::Refund => 'Refund',
            self::AdminAdjustment => 'Admin Adjustment',
        };
    }
}
