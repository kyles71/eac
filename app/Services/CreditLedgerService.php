<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CreditGrantStatus;
use App\Enums\CreditTransactionType;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\CreditGrant;
use App\Models\CreditTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreditLedgerService
{
    /**
     * @param  list<int>  $productIds
     */
    public function issue(
        User $recipient,
        int $amount,
        string $description,
        ?User $issuer = null,
        ?CarbonInterface $expiresOn = null,
        ?ProductType $restrictedToProductType = null,
        array $productIds = [],
        ?Model $source = null,
        CreditTransactionType $transactionType = CreditTransactionType::AdminGrant,
    ): CreditGrant {
        $description = mb_trim($description);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Credit amount must be greater than zero.');
        }

        if ($description === '') {
            throw new InvalidArgumentException('A description is required.');
        }

        if ($expiresOn !== null && $expiresOn->lt(now('America/New_York')->startOfDay())) {
            throw new InvalidArgumentException('The expiration date cannot be in the past.');
        }

        return DB::transaction(function () use (
            $recipient,
            $amount,
            $description,
            $issuer,
            $expiresOn,
            $restrictedToProductType,
            $productIds,
            $source,
            $transactionType,
        ): CreditGrant {
            $grant = CreditGrant::query()->create([
                'user_id' => $recipient->id,
                'granted_by_user_id' => $issuer?->id,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'initial_amount' => $amount,
                'remaining_amount' => $amount,
                'description' => $description,
                'restricted_to_product_type' => $restrictedToProductType,
                'has_product_restrictions' => $productIds !== [],
                'expires_on' => $expiresOn?->toDateString(),
            ]);

            $grant->products()->sync($productIds);

            $this->recordTransaction(
                grant: $grant,
                amount: $amount,
                type: $transactionType,
                reference: $source,
                description: $description,
                performedBy: $issuer,
            );

            return $grant->refresh()->load('products');
        });
    }

    public function revoke(CreditGrant $grant, User $revokedBy, string $reason): CreditGrant
    {
        $reason = mb_trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A revocation reason is required.');
        }

        return DB::transaction(function () use ($grant, $revokedBy, $reason): CreditGrant {
            $lockedGrant = CreditGrant::query()->lockForUpdate()->findOrFail($grant->id);

            if ($lockedGrant->status() !== CreditGrantStatus::Active) {
                throw new InvalidArgumentException('Only active credit with an unused balance can be revoked.');
            }

            $lockedGrant->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $revokedBy->id,
                'revocation_reason' => $reason,
            ]);

            $this->recordTransaction(
                grant: $lockedGrant,
                amount: -$lockedGrant->remaining_amount,
                type: CreditTransactionType::Revocation,
                reference: $lockedGrant,
                description: $reason,
                performedBy: $revokedBy,
            );

            return $lockedGrant->refresh();
        });
    }

    public function applyRestrictedToOrder(Order $order): int
    {
        $order->loadMissing('orderItems.product');

        /** @var EloquentCollection<int, CreditGrant> $grants */
        $grants = CreditGrant::query()
            ->where('user_id', $order->user_id)
            ->available()
            ->restricted()
            ->with('products')
            ->lockForUpdate()
            ->get();

        $lineRemaining = $order->orderItems
            ->mapWithKeys(fn (OrderItem $orderItem): array => [$orderItem->id => $orderItem->total_price])
            ->all();

        return $this->consumeRestrictedGrants(
            grants: $grants,
            orderItems: $order->orderItems,
            lineRemaining: $lineRemaining,
            maximumAmount: $order->total,
            order: $order,
        );
    }

    public function applyUnrestrictedToOrder(Order $order, int $requestedAmount): int
    {
        $remainingToApply = min($requestedAmount, $order->total);

        if ($remainingToApply <= 0) {
            return 0;
        }

        /** @var EloquentCollection<int, CreditGrant> $grants */
        $grants = CreditGrant::query()
            ->where('user_id', $order->user_id)
            ->available()
            ->unrestricted()
            ->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $totalApplied = 0;

        foreach ($grants as $grant) {
            if ($remainingToApply <= 0) {
                break;
            }

            $amount = min($grant->remaining_amount, $remainingToApply);
            $this->debitGrant($grant, $order, $amount);
            $totalApplied += $amount;
            $remainingToApply -= $amount;
        }

        return $totalApplied;
    }

    public function restoreOrder(Order $order): int
    {
        $movements = CreditTransaction::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->id)
            ->whereNotNull('credit_grant_id')
            ->whereIn('type', [CreditTransactionType::CheckoutDebit, CreditTransactionType::Refund])
            ->get()
            ->groupBy('credit_grant_id');

        $totalRestored = 0;

        foreach ($movements as $grantId => $transactions) {
            $amountToRestore = max(0, -(int) $transactions->sum('amount'));

            if ($amountToRestore <= 0) {
                continue;
            }

            $grant = CreditGrant::query()->lockForUpdate()->find($grantId);

            if ($grant === null) {
                continue;
            }

            $grant->increment('remaining_amount', $amountToRestore);
            $grant->refresh();

            $this->recordTransaction(
                grant: $grant,
                amount: $amountToRestore,
                type: CreditTransactionType::Refund,
                reference: $order,
                description: "Restored credit from cancelled order #{$order->id}",
            );

            $totalRestored += $amountToRestore;
        }

        return $totalRestored;
    }

    public function availableUnrestrictedBalance(User $user): int
    {
        return (int) $user->creditGrants()->available()->unrestricted()->sum('remaining_amount');
    }

    public function availableRestrictedBalance(User $user): int
    {
        return (int) $user->creditGrants()->available()->restricted()->sum('remaining_amount');
    }

    public function previewUnrestrictedBalance(User $user): int
    {
        /** @var EloquentCollection<int, CreditGrant> $grants */
        $grants = $user->creditGrants()->usable()->unrestricted()->get();
        $pendingDebits = $this->pendingDebitAmounts($user);

        return (int) $grants->sum(
            fn (CreditGrant $grant): int => $grant->remaining_amount + ($pendingDebits[$grant->id] ?? 0),
        );
    }

    /**
     * @param  Collection<int, array{product: Product, amount: int}>  $items
     */
    public function previewRestrictedAmount(User $user, Collection $items, int $maximumAmount): int
    {
        /** @var EloquentCollection<int, CreditGrant> $grants */
        $grants = $user->creditGrants()->usable()->restricted()->with('products')->get();
        $pendingDebits = $this->pendingDebitAmounts($user);

        foreach ($grants as $grant) {
            $grant->remaining_amount += $pendingDebits[$grant->id] ?? 0;
        }

        $orderItems = $items->values();
        $lineRemaining = $orderItems->mapWithKeys(
            fn (array $item, int $index): array => [$index => $item['amount']],
        )->all();

        return $this->previewRestrictedGrants($grants, $orderItems, $lineRemaining, $maximumAmount);
    }

    /**
     * @param  EloquentCollection<int, CreditGrant>  $grants
     * @param  EloquentCollection<int, OrderItem>  $orderItems
     * @param  array<int, int>  $lineRemaining
     */
    private function consumeRestrictedGrants(
        EloquentCollection $grants,
        EloquentCollection $orderItems,
        array $lineRemaining,
        int $maximumAmount,
        Order $order,
    ): int {
        $totalApplied = 0;

        foreach ($this->sortRestrictedGrants($grants, $orderItems) as $grant) {
            $grantApplied = 0;

            foreach ($orderItems as $orderItem) {
                if ($totalApplied >= $maximumAmount || $grantApplied >= $grant->remaining_amount) {
                    break;
                }

                if (! $grant->appliesToProduct($orderItem->product)) {
                    continue;
                }

                $availableForLine = $lineRemaining[$orderItem->id] ?? 0;
                $amount = min(
                    $availableForLine,
                    $grant->remaining_amount - $grantApplied,
                    $maximumAmount - $totalApplied,
                );

                if ($amount <= 0) {
                    continue;
                }

                $lineRemaining[$orderItem->id] -= $amount;
                $grantApplied += $amount;
                $totalApplied += $amount;
            }

            if ($grantApplied > 0) {
                $this->debitGrant($grant, $order, $grantApplied);
            }
        }

        return $totalApplied;
    }

    /**
     * @param  EloquentCollection<int, CreditGrant>  $grants
     * @param  Collection<int, array{product: Product, amount: int}>  $items
     * @param  array<int, int>  $lineRemaining
     */
    private function previewRestrictedGrants(
        EloquentCollection $grants,
        Collection $items,
        array $lineRemaining,
        int $maximumAmount,
    ): int {
        $totalApplied = 0;

        foreach ($this->sortRestrictedGrants($grants, $items) as $grant) {
            $grantApplied = 0;

            foreach ($items as $index => $item) {
                if ($totalApplied >= $maximumAmount || $grantApplied >= $grant->remaining_amount) {
                    break;
                }

                if (! $grant->appliesToProduct($item['product'])) {
                    continue;
                }

                $amount = min(
                    $lineRemaining[$index] ?? 0,
                    $grant->remaining_amount - $grantApplied,
                    $maximumAmount - $totalApplied,
                );

                $lineRemaining[$index] -= $amount;
                $grantApplied += $amount;
                $totalApplied += $amount;
            }
        }

        return $totalApplied;
    }

    /**
     * @template TItem of OrderItem|array{product: Product, amount: int}
     *
     * @param  EloquentCollection<int, CreditGrant>  $grants
     * @param  Collection<int, TItem>  $items
     * @return Collection<int, CreditGrant>
     */
    private function sortRestrictedGrants(EloquentCollection $grants, Collection $items): Collection
    {
        return $grants->sort(function (CreditGrant $left, CreditGrant $right) use ($items): int {
            $leftEligibleCount = $this->eligibleItemCount($left, $items);
            $rightEligibleCount = $this->eligibleItemCount($right, $items);

            return [$leftEligibleCount, $left->expires_on?->toDateString() ?? '9999-12-31', $left->created_at, $left->id]
                <=> [$rightEligibleCount, $right->expires_on?->toDateString() ?? '9999-12-31', $right->created_at, $right->id];
        })->values();
    }

    /**
     * @template TItem of OrderItem|array{product: Product, amount: int}
     *
     * @param  Collection<int, TItem>  $items
     */
    private function eligibleItemCount(CreditGrant $grant, Collection $items): int
    {
        return $items->filter(function (OrderItem|array $item) use ($grant): bool {
            $product = $item instanceof OrderItem ? $item->product : $item['product'];

            return $grant->appliesToProduct($product);
        })->count();
    }

    private function debitGrant(CreditGrant $grant, Order $order, int $amount): void
    {
        $grant->decrement('remaining_amount', $amount);
        $grant->refresh();

        $this->recordTransaction(
            grant: $grant,
            amount: -$amount,
            type: CreditTransactionType::CheckoutDebit,
            reference: $order,
            description: "Applied to order #{$order->id}",
            performedBy: $order->user,
        );
    }

    /** @return array<int, int> */
    private function pendingDebitAmounts(User $user): array
    {
        return CreditTransaction::query()
            ->selectRaw('credit_grant_id, SUM(-amount) as reserved_amount')
            ->where('user_id', $user->id)
            ->where('type', CreditTransactionType::CheckoutDebit)
            ->whereNotNull('credit_grant_id')
            ->whereHasMorph(
                'reference',
                [Order::class],
                fn ($query) => $query->where('status', OrderStatus::Pending),
            )
            ->groupBy('credit_grant_id')
            ->pluck('reserved_amount', 'credit_grant_id')
            ->map(fn (mixed $amount): int => (int) $amount)
            ->all();
    }

    private function recordTransaction(
        CreditGrant $grant,
        int $amount,
        CreditTransactionType $type,
        ?Model $reference = null,
        ?string $description = null,
        ?User $performedBy = null,
    ): CreditTransaction {
        return $grant->transactions()->create([
            'user_id' => $grant->user_id,
            'performed_by_user_id' => $performedBy?->id,
            'amount' => $amount,
            'type' => $type,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'description' => $description,
        ]);
    }
}
