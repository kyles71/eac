<?php

declare(strict_types=1);

use App\Support\Store\AllocateOrderItemPayments;

it('distributes rounding cents without exceeding any order line', function (): void {
    $allocations = app(AllocateOrderItemPayments::class)->allocateProportionally([
        101 => 100,
        102 => 100,
        103 => 100,
    ], 299);

    expect($allocations)->toBe([
        101 => 100,
        102 => 100,
        103 => 99,
    ])->and(array_sum($allocations))->toBe(299);
});

it('preserves allocation totals and line limits', function (array $lineAmounts, int $amount): void {
    $allocations = app(AllocateOrderItemPayments::class)->allocateProportionally($lineAmounts, $amount);
    $normalizedLineAmounts = array_map(fn (int $lineAmount): int => max(0, $lineAmount), $lineAmounts);

    expect(array_keys($allocations))->toBe(array_keys($lineAmounts))
        ->and(array_sum($allocations))->toBe(min(max(0, $amount), array_sum($normalizedLineAmounts)));

    foreach ($allocations as $itemId => $allocation) {
        expect($allocation)->toBeGreaterThanOrEqual(0)
            ->toBeLessThanOrEqual($normalizedLineAmounts[$itemId]);
    }
})->with([
    'uneven proportions' => [[1 => 101, 2 => 202, 3 => 303], 305],
    'allocation exceeds total' => [[1 => 50, 2 => 100], 500],
    'zero-value line' => [[1 => 0, 2 => 100], 99],
    'negative amount' => [[1 => 100, 2 => 100], -1],
]);
