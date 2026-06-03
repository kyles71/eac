<?php

declare(strict_types=1);

namespace App\Support;

final class PaymentPlanFee
{
    public const float RATE = 0.03;

    public const string LABEL = 'Payment Plan Fee (3%)';

    public static function calculate(int $financedAmount): int
    {
        if ($financedAmount <= 0) {
            return 0;
        }

        return (int) round($financedAmount * self::RATE);
    }
}
