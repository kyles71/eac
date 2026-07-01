<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InstallmentStatus;
use App\Enums\OrderStatus;
use App\Models\Installment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class DashboardAccountSummaryService
{
    /**
     * @return array{open_enrollments: int, store_credit: int, limited_use_credit: int, next_installment: ?Installment, next_payment_total: int}
     */
    public function for(User $user): array
    {
        $nextInstallments = $this->nextInstallmentsFor($user);
        $nextInstallment = $nextInstallments->first();

        return [
            'open_enrollments' => $user->enrollments()
                ->whereNull('student_id')
                ->count(),
            'store_credit' => $user->availableStoreCreditBalance(),
            'limited_use_credit' => $user->availableRestrictedCreditBalance(),
            'next_installment' => $nextInstallment instanceof Installment ? $nextInstallment : null,
            'next_payment_total' => (int) $nextInstallments->sum('amount'),
        ];
    }

    public function nextInstallmentFor(User $user): ?Installment
    {
        return $this->pendingInstallmentsQueryFor($user)
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return EloquentCollection<int, Installment>
     */
    public function nextInstallmentsFor(User $user): EloquentCollection
    {
        $nextInstallment = $this->nextInstallmentFor($user);

        if (! $nextInstallment instanceof Installment) {
            return new EloquentCollection;
        }

        return $this->pendingInstallmentsQueryFor($user)
            ->whereDate('due_date', $nextInstallment->due_date->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Builder<Installment>
     */
    private function pendingInstallmentsQueryFor(User $user): Builder
    {
        return Installment::query()
            ->where('status', InstallmentStatus::Pending)
            ->whereHas('paymentPlan.order', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('status', OrderStatus::Completed));
    }
}
