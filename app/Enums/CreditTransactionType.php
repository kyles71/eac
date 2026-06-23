<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CreditTransactionType: string implements HasLabel
{
    case OpeningBalance = 'OpeningBalance';
    case AdminGrant = 'AdminGrant';
    case GiftCardRedemption = 'GiftCardRedemption';
    case CheckoutDebit = 'CheckoutDebit';
    case Refund = 'Refund';
    case AdminAdjustment = 'AdminAdjustment';
    case Revocation = 'Revocation';

    public function getLabel(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening Balance',
            self::AdminGrant => 'Credit Issued',
            self::GiftCardRedemption => 'Gift Card Redemption',
            self::CheckoutDebit => 'Checkout Debit',
            self::Refund => 'Refund',
            self::AdminAdjustment => 'Admin Adjustment',
            self::Revocation => 'Credit Revoked',
        };
    }
}
