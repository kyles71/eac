<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\OrderStatus;
use App\Models\Installment;
use App\Models\PaymentPlan;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class MarkInstallmentsPaid
{
    /**
     * @param  list<int|string>  $installmentIds
     */
    public function handle(PaymentPlan $paymentPlan, array $installmentIds): int
    {
        $installmentIds = collect($installmentIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($installmentIds === []) {
            throw new InvalidArgumentException('Select at least one installment to mark as paid.');
        }

        return DB::transaction(function () use ($paymentPlan, $installmentIds): int {
            /** @var PaymentPlan $lockedPaymentPlan */
            $lockedPaymentPlan = PaymentPlan::query()
                ->with('order')
                ->lockForUpdate()
                ->findOrFail($paymentPlan->id);

            if (in_array($lockedPaymentPlan->order?->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
                throw new DomainException('This payment plan is no longer collectible.');
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, Installment> $installments */
            $installments = $lockedPaymentPlan->installments()
                ->whereKey($installmentIds)
                ->reschedulable()
                ->notBlockedByRefundCancellation()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($installments->count() !== count($installmentIds)) {
                throw new DomainException('One or more selected installments can no longer be marked as paid.');
            }

            $hasActiveAttempt = Installment::query()
                ->whereKey($installmentIds)
                ->withoutActivePaymentAttempt()
                ->count() !== count($installmentIds);

            if ($hasActiveAttempt) {
                throw new DomainException('A payment for one or more selected installments is already in progress.');
            }

            foreach ($installments as $installment) {
                $installment->markPaid();
            }

            return $installments->count();
        }, attempts: 3);
    }
}
