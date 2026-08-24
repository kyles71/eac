<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Contracts\StripeServiceContract;
use App\Enums\OrderRefundPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\OrderRefundPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Stripe\Exception\ApiConnectionException;
use Throwable;

final readonly class IssueOrderRefundAction
{
    public function __construct(
        private StripeServiceContract $stripeService,
        private ReconcileOrderRefundAction $reconcileOrderRefund,
    ) {}

    /**
     * @param  list<int>  $enrollmentIds
     */
    public function handle(
        Order $order,
        User $processedBy,
        int $amount,
        string $reason,
        array $enrollmentIds = [],
        bool $cancelRemainingInstallments = false,
        bool $restoreStoreCredit = false,
    ): OrderRefund {
        $refund = DB::transaction(function () use (
            $order,
            $processedBy,
            $amount,
            $reason,
            $enrollmentIds,
            $cancelRemainingInstallments,
            $restoreStoreCredit,
        ): OrderRefund {
            /** @var Order|null $lockedOrder */
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->find($order->id);

            if ($lockedOrder === null || ! in_array($lockedOrder->status, [
                OrderStatus::Completed,
                OrderStatus::PartiallyRefunded,
            ], true)) {
                throw new InvalidArgumentException('Only completed orders can be refunded.');
            }

            $reason = mb_trim($reason);

            if ($reason === '' || mb_strlen($reason) > 2000) {
                throw new InvalidArgumentException('A refund reason of 2,000 characters or fewer is required.');
            }

            $refundableAmount = $lockedOrder->refundableAmount();

            if ($amount < 1 || $amount > $refundableAmount) {
                throw new InvalidArgumentException('The refund amount exceeds the remaining refundable balance.');
            }

            $validatedEnrollments = $this->validatedEnrollments($lockedOrder, $enrollmentIds);

            if ($restoreStoreCredit && (
                $amount !== $refundableAmount
                || ($lockedOrder->hasChargeableInstallments() && ! $cancelRemainingInstallments)
            )) {
                throw new InvalidArgumentException('Store credit can only be restored when the remaining order balance is fully refunded.');
            }

            /** @var OrderRefund $refund */
            $refund = $lockedOrder->refunds()->create([
                'processed_by_user_id' => $processedBy->id,
                'amount' => $amount,
                'reason' => $reason,
                'cancel_remaining_installments' => $cancelRemainingInstallments,
                'restore_store_credit' => $restoreStoreCredit,
                'enrollment_ids' => $validatedEnrollments['ids'],
                'enrollment_details' => $validatedEnrollments['details'],
            ]);

            $remaining = $amount;

            foreach ($lockedOrder->refundablePaymentSources() as $source) {
                if ($remaining === 0) {
                    break;
                }

                $allocatedAmount = min($remaining, $source['amount']);

                $refund->payments()->create([
                    'stripe_payment_intent_id' => $source['payment_intent_id'],
                    'amount' => $allocatedAmount,
                    'status' => OrderRefundPaymentStatus::Processing,
                ]);

                $remaining -= $allocatedAmount;
            }

            if ($remaining !== 0) {
                throw new InvalidArgumentException('The refund could not be allocated to the order payments.');
            }

            return $refund;
        }, attempts: 3);

        $refund->load('payments');

        /** @var OrderRefundPayment $payment */
        foreach ($refund->payments as $payment) {
            try {
                $stripeRefund = $this->stripeService->refundPaymentIntent(
                    paymentIntentId: $payment->stripe_payment_intent_id,
                    amount: $payment->amount,
                    metadata: [
                        'order_id' => (string) $refund->order_id,
                        'order_refund_id' => (string) $refund->id,
                        'order_refund_payment_id' => (string) $payment->id,
                    ],
                    idempotencyKey: $payment->idempotencyKey(),
                );

                $status = OrderRefundPaymentStatus::fromStripe($stripeRefund->status);

                $payment->recordStripeStatus(
                    stripeRefundId: $stripeRefund->id,
                    status: $status,
                    failureReason: $stripeRefund->failure_reason,
                );
            } catch (Throwable $exception) {
                $payment->recordStripeStatus(
                    stripeRefundId: null,
                    status: $exception instanceof ApiConnectionException
                        ? OrderRefundPaymentStatus::Pending
                        : OrderRefundPaymentStatus::Failed,
                    failureReason: $exception->getMessage(),
                );
            }
        }

        return $this->reconcileOrderRefund->handle($refund);
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @return array{
     *     ids: list<int>,
     *     details: list<array{id: int, student: string, course: string}>
     * }
     */
    private function validatedEnrollments(Order $order, array $enrollmentIds): array
    {
        $ids = collect($enrollmentIds)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return ['ids' => [], 'details' => []];
        }

        $enrollments = Enrollment::query()
            ->with(['course', 'student'])
            ->whereIn('id', $ids)
            ->whereHas('orderItem', fn ($query) => $query->where('order_id', $order->id))
            ->orderBy('id')
            ->get();

        if ($enrollments->count() !== $ids->count()) {
            throw new InvalidArgumentException('One or more selected enrollments do not belong to this order.');
        }

        return [
            'ids' => $enrollments->pluck('id')->all(),
            'details' => $enrollments
                ->map(function (Enrollment $enrollment): array {
                    return [
                        'id' => $enrollment->id,
                        'student' => $enrollment->student_id === null
                            ? 'Unassigned seat'
                            : $enrollment->student->fullName,
                        'course' => $enrollment->course->name,
                    ];
                })
                ->all(),
        ];
    }
}
