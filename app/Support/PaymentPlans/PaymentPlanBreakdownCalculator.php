<?php

declare(strict_types=1);

namespace App\Support\PaymentPlans;

use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\PaymentPlanTemplate;
use App\Models\Product;
use App\Support\PaymentPlanFee;
use Illuminate\Support\Collection;

final class PaymentPlanBreakdownCalculator
{
    /**
     * @param  Collection<int, CartItem|OrderItem>  $items
     * @param  array<int, int>  $restrictedCreditByItemKey
     */
    public function calculate(
        Collection $items,
        PaymentPlanTemplate $template,
        int $discountAmount,
        array $restrictedCreditByItemKey = [],
        int $creditAmount = 0,
    ): PaymentPlanBreakdown {
        $rows = $this->rows($items, $template);

        $paymentPlanItemsAmount = $this->sum($rows, eligible: true, field: 'amount');
        $payInFullItemsAmount = $this->sum($rows, eligible: false, field: 'amount');

        $discountSplit = $this->applyReduction($rows, $discountAmount);
        $restrictedCreditSplit = $this->applyRestrictedCredit($rows, $restrictedCreditByItemKey);
        $creditSplit = $this->applyReduction($rows, $creditAmount);

        $principal = $this->sum($rows, eligible: true);
        $payInFullAmount = $this->sum($rows, eligible: false);
        $fee = PaymentPlanFee::calculate($principal);
        $installmentTotal = $principal + $fee;
        $amountDueToday = $payInFullAmount;

        if ($installmentTotal > 0) {
            $amountDueToday += $template->installmentAmounts($installmentTotal)['first'];
        }

        return new PaymentPlanBreakdown(
            payInFullAmount: $payInFullAmount,
            payInFullItemsAmount: $payInFullItemsAmount,
            principal: $principal,
            paymentPlanItemsAmount: $paymentPlanItemsAmount,
            fee: $fee,
            installmentTotal: $installmentTotal,
            amountDueToday: $amountDueToday,
            discountAmount: min($discountAmount, $this->total($items)),
            paymentPlanDiscountAmount: $discountSplit['payment_plan'],
            payInFullDiscountAmount: $discountSplit['pay_in_full'],
            restrictedCreditAmount: $restrictedCreditSplit['payment_plan'] + $restrictedCreditSplit['pay_in_full'],
            paymentPlanRestrictedCreditAmount: $restrictedCreditSplit['payment_plan'],
            payInFullRestrictedCreditAmount: $restrictedCreditSplit['pay_in_full'],
            creditAmount: $creditSplit['payment_plan'] + $creditSplit['pay_in_full'],
            paymentPlanCreditAmount: $creditSplit['payment_plan'],
            payInFullCreditAmount: $creditSplit['pay_in_full'],
        );
    }

    /**
     * @param  Collection<int, CartItem|OrderItem>  $items
     * @return Collection<int, CartItem|OrderItem>
     */
    public function itemsForCreditApplication(Collection $items, PaymentPlanTemplate $template): Collection
    {
        [$eligible, $ineligible] = $items->partition(
            fn (CartItem|OrderItem $item): bool => $template->matchesProduct($item->product, $this->lineAmount($item)),
        );

        return $eligible
            ->values()
            ->concat($ineligible->values())
            ->values();
    }

    /**
     * @param  Collection<int, CartItem|OrderItem>  $items
     * @return array<int, int>
     */
    public function lineAmountsAfterDiscount(
        Collection $items,
        PaymentPlanTemplate $template,
        int $discountAmount,
    ): array {
        $rows = $this->rows($items, $template);

        $this->applyReduction($rows, $discountAmount);

        return collect($rows)
            ->mapWithKeys(fn (array $row): array => [$row['key'] => $row['remaining']])
            ->all();
    }

    /**
     * @param  Collection<int, CartItem|OrderItem>  $items
     * @return array<int, array{key: int, product: Product, amount: int, remaining: int, eligible: bool}>
     */
    private function rows(Collection $items, PaymentPlanTemplate $template): array
    {
        return $items
            ->map(function (CartItem|OrderItem $item) use ($template): array {
                $product = $item->product;
                $amount = $this->lineAmount($item);

                return [
                    'key' => (int) $item->id,
                    'product' => $product,
                    'amount' => $amount,
                    'remaining' => $amount,
                    'eligible' => $template->matchesProduct($product, $amount),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{remaining: int, eligible: bool}>  $rows
     * @return array{payment_plan: int, pay_in_full: int}
     */
    private function applyReduction(array &$rows, int $amount): array
    {
        $remainingReduction = max(0, $amount);
        $applied = $this->emptyReductionSplit();

        foreach ([true, false] as $eligible) {
            foreach ($rows as &$row) {
                if ($remainingReduction <= 0) {
                    break 2;
                }

                if ($row['eligible'] !== $eligible) {
                    continue;
                }

                $appliedToRow = min($row['remaining'], $remainingReduction);
                $row['remaining'] -= $appliedToRow;
                $remainingReduction -= $appliedToRow;
                $applied[$row['eligible'] ? 'payment_plan' : 'pay_in_full'] += $appliedToRow;
            }
        }

        return $applied;
    }

    /**
     * @param  array<int, array{key: int, remaining: int, eligible: bool}>  $rows
     * @param  array<int, int>  $restrictedCreditByItemKey
     * @return array{payment_plan: int, pay_in_full: int}
     */
    private function applyRestrictedCredit(array &$rows, array $restrictedCreditByItemKey): array
    {
        $applied = $this->emptyReductionSplit();

        foreach ($rows as &$row) {
            $amount = $restrictedCreditByItemKey[$row['key']] ?? 0;

            if ($amount <= 0) {
                continue;
            }

            $appliedToRow = min($row['remaining'], $amount);
            $row['remaining'] -= $appliedToRow;
            $applied[$row['eligible'] ? 'payment_plan' : 'pay_in_full'] += $appliedToRow;
        }

        return $applied;
    }

    /** @param array<int, array{amount: int, remaining: int, eligible: bool}> $rows */
    private function sum(array $rows, bool $eligible, string $field = 'remaining'): int
    {
        return (int) collect($rows)
            ->where('eligible', $eligible)
            ->sum($field);
    }

    /** @param Collection<int, CartItem|OrderItem> $items */
    private function total(Collection $items): int
    {
        return (int) $items->sum(fn (CartItem|OrderItem $item): int => $this->lineAmount($item));
    }

    private function lineAmount(CartItem|OrderItem $item): int
    {
        if ($item instanceof OrderItem) {
            return $item->total_price;
        }

        return $item->lineTotal();
    }

    /** @return array{payment_plan: int, pay_in_full: int} */
    private function emptyReductionSplit(): array
    {
        return [
            'payment_plan' => 0,
            'pay_in_full' => 0,
        ];
    }
}
