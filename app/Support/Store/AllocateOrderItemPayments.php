<?php

declare(strict_types=1);

namespace App\Support\Store;

use App\Models\Order;
use App\Models\OrderItem;

final class AllocateOrderItemPayments
{
    /**
     * @param  array<int, int>  $restrictedCreditByOrderItemId
     */
    public function handle(Order $order, array $restrictedCreditByOrderItemId): void
    {
        $order->loadMissing('orderItems');
        $discountByItemId = $this->allocateProportionally(
            $order->orderItems->mapWithKeys(
                fn (OrderItem $item): array => [$item->id => $item->total_price],
            )->all(),
            $order->discount_amount,
        );
        $remainingByItemId = [];

        /** @var OrderItem $item */
        foreach ($order->orderItems as $item) {
            $remainingByItemId[$item->id] = max(
                0,
                $item->total_price
                    - ($discountByItemId[$item->id] ?? 0)
                    - ($restrictedCreditByOrderItemId[$item->id] ?? 0),
            );
        }

        $creditByItemId = $this->allocateSequentially($remainingByItemId, $order->credit_applied);

        foreach ($order->orderItems as $item) {
            $discount = $discountByItemId[$item->id] ?? 0;
            $restrictedCredit = $restrictedCreditByOrderItemId[$item->id] ?? 0;
            $credit = $creditByItemId[$item->id] ?? 0;

            $item->update([
                'discount_allocated' => $discount,
                'restricted_credit_allocated' => $restrictedCredit,
                'credit_allocated' => $credit,
                'stripe_allocated' => max(0, $item->total_price - $discount - $restrictedCredit - $credit),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $amountsByItemId
     * @return array<int, int>
     */
    public function allocateProportionally(array $amountsByItemId, int $amount): array
    {
        $total = array_sum($amountsByItemId);

        if ($amount <= 0 || $total <= 0) {
            return array_fill_keys(array_keys($amountsByItemId), 0);
        }

        $amount = min($amount, $total);
        $allocated = [];
        $remaining = $amount;
        $lastItemId = array_key_last($amountsByItemId);

        foreach ($amountsByItemId as $itemId => $lineAmount) {
            $lineAllocation = $itemId === $lastItemId
                ? $remaining
                : min($lineAmount, intdiv($amount * $lineAmount, $total));
            $allocated[$itemId] = $lineAllocation;
            $remaining -= $lineAllocation;
        }

        return $allocated;
    }

    /**
     * @param  array<int, int>  $availableByItemId
     * @return array<int, int>
     */
    private function allocateSequentially(array $availableByItemId, int $amount): array
    {
        $remaining = $amount;
        $allocated = [];

        foreach ($availableByItemId as $itemId => $available) {
            $allocated[$itemId] = min($available, $remaining);
            $remaining -= $allocated[$itemId];
        }

        return $allocated;
    }
}
