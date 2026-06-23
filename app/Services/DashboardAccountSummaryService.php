<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Models\Installment;
use App\Models\User;

final class DashboardAccountSummaryService
{
    /**
     * @return array{open_enrollments: int, store_credit: int, limited_use_credit: int, next_installment: ?Installment}
     */
    public function for(User $user): array
    {
        $nextInstallment = $this->nextInstallmentFor($user);

        return [
            'open_enrollments' => $user->enrollments()
                ->whereNull('student_id')
                ->count(),
            'store_credit' => $user->availableStoreCreditBalance(),
            'limited_use_credit' => $user->availableRestrictedCreditBalance(),
            'next_installment' => $nextInstallment,
        ];
    }

    public function nextInstallmentFor(User $user): ?Installment
    {
        return Installment::query()
            ->where('status', InstallmentStatus::Pending)
            ->whereHas('paymentPlan.order', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('status', OrderStatus::Completed))
            ->orderBy('due_date')
            ->first();
    }
}
