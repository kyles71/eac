<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\InstallmentStatus;
use App\Enums\OrderRefundPaymentStatus;
use App\Enums\OrderRefundStatus;
use App\Enums\OrderStatus;
use App\Models\Enrollment;
use App\Models\OrderRefund;
use App\Services\CreditLedgerService;
use Illuminate\Support\Facades\DB;

final readonly class ReconcileOrderRefundAction
{
    public function __construct(private CreditLedgerService $creditLedger) {}

    public function handle(OrderRefund $refund): OrderRefund
    {
        return DB::transaction(function () use ($refund): OrderRefund {
            /** @var OrderRefund $lockedRefund */
            $lockedRefund = OrderRefund::query()
                ->with(['payments', 'order.paymentPlan'])
                ->lockForUpdate()
                ->findOrFail($refund->id);

            $statuses = $lockedRefund->payments->pluck('status');
            $allSucceeded = $statuses->isNotEmpty()
                && $statuses->every(fn (OrderRefundPaymentStatus $status): bool => $status === OrderRefundPaymentStatus::Succeeded);
            $allFailed = $statuses->isNotEmpty()
                && $statuses->every(fn (OrderRefundPaymentStatus $status): bool => in_array($status, [
                    OrderRefundPaymentStatus::Failed,
                    OrderRefundPaymentStatus::Canceled,
                ], true));
            $hasFailed = $statuses->contains(fn (OrderRefundPaymentStatus $status): bool => in_array($status, [
                OrderRefundPaymentStatus::Failed,
                OrderRefundPaymentStatus::Canceled,
            ], true));

            $status = match (true) {
                $allSucceeded => OrderRefundStatus::Succeeded,
                $allFailed => OrderRefundStatus::Failed,
                $hasFailed => OrderRefundStatus::PartiallyFailed,
                default => OrderRefundStatus::Pending,
            };

            $lockedRefund->update([
                'status' => $status,
                'completed_at' => $allSucceeded ? ($lockedRefund->completed_at ?? now()) : null,
            ]);

            if ($allSucceeded) {
                $this->applySuccessfulRefundEffects($lockedRefund);
            }

            $order = $lockedRefund->order;
            $successfulAmount = $order->successfulRefundAmount();

            if ($successfulAmount > 0) {
                $order->update([
                    'status' => $successfulAmount >= $order->capturedStripeAmount()
                        && ! $order->hasChargeableInstallments()
                            ? OrderStatus::Refunded
                            : OrderStatus::PartiallyRefunded,
                ]);
            }

            return $lockedRefund->refresh()->load(['payments', 'order']);
        }, attempts: 3);
    }

    private function applySuccessfulRefundEffects(OrderRefund $refund): void
    {
        $order = $refund->order;

        if ($refund->enrollments_removed_at === null) {
            $enrollmentIds = collect($refund->enrollment_ids ?? [])
                ->filter(fn (mixed $id): bool => is_numeric($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            Enrollment::query()
                ->whereIn('id', $enrollmentIds)
                ->whereHas('orderItem', fn ($query) => $query->where('order_id', $order->id))
                ->eachById(fn (Enrollment $enrollment) => $enrollment->delete());

            $refund->update(['enrollments_removed_at' => now()]);
        }

        if ($refund->cancel_remaining_installments && $refund->installments_cancelled_at === null) {
            $order->paymentPlan?->installments()
                ->whereIn('status', [
                    InstallmentStatus::Pending,
                    InstallmentStatus::Failed,
                    InstallmentStatus::Overdue,
                ])
                ->update(['status' => InstallmentStatus::Cancelled]);

            $refund->update(['installments_cancelled_at' => now()]);
        }

        if ($refund->restore_store_credit && $refund->credit_restored_at === null) {
            $this->creditLedger->restoreOrder(
                $order,
                description: "Restored credit from refunded order #{$order->id}",
            );

            $refund->update(['credit_restored_at' => now()]);
        }
    }
}
