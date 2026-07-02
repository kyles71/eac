<?php

declare(strict_types=1);

namespace App\Support\PaymentPlans;

final readonly class PaymentPlanBreakdown
{
    public function __construct(
        public int $payInFullAmount,
        public int $payInFullItemsAmount,
        public int $principal,
        public int $paymentPlanItemsAmount,
        public int $fee,
        public int $installmentTotal,
        public int $amountDueToday,
        public int $discountAmount,
        public int $paymentPlanDiscountAmount,
        public int $payInFullDiscountAmount,
        public int $restrictedCreditAmount,
        public int $paymentPlanRestrictedCreditAmount,
        public int $payInFullRestrictedCreditAmount,
        public int $creditAmount,
        public int $paymentPlanCreditAmount,
        public int $payInFullCreditAmount,
    ) {}

    public function hasPrincipal(): bool
    {
        return $this->principal > 0;
    }
}
