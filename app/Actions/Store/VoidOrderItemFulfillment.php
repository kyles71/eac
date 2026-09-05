<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\OrderItem;
use App\Models\OrderItemFulfillment;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class VoidOrderItemFulfillment
{
    public function handle(
        OrderItemFulfillment $fulfillment,
        ?User $voidedBy,
        string $reason,
    ): bool {
        $reason = $this->normalizeReason($reason);

        return DB::transaction(function () use ($fulfillment, $reason, $voidedBy): bool {
            /** @var OrderItemFulfillment|null $lockedFulfillment */
            $lockedFulfillment = OrderItemFulfillment::query()
                ->lockForUpdate()
                ->find($fulfillment->id);

            if (! $lockedFulfillment instanceof OrderItemFulfillment) {
                throw new InvalidArgumentException('The fulfillment record could not be found.');
            }

            if (! $lockedFulfillment->isActive()) {
                return false;
            }

            $lockedFulfillment->update([
                'voided_by_user_id' => $voidedBy?->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            $this->syncOrderItem($lockedFulfillment->order_item_id);

            return true;
        });
    }

    public function forSource(Model $source, ?User $voidedBy, string $reason): int
    {
        $reason = $this->normalizeReason($reason);

        return DB::transaction(function () use ($reason, $source, $voidedBy): int {
            $fulfillments = OrderItemFulfillment::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->get();
            $orderItemIds = $fulfillments->pluck('order_item_id')->unique();

            foreach ($fulfillments as $fulfillment) {
                $fulfillment->update([
                    'voided_by_user_id' => $voidedBy?->id,
                    'voided_at' => now(),
                    'void_reason' => $reason,
                ]);
            }

            foreach ($orderItemIds as $orderItemId) {
                $this->syncOrderItem((int) $orderItemId);
            }

            return $fulfillments->count();
        });
    }

    private function normalizeReason(string $reason): string
    {
        $reason = (string) str($reason)->squish();

        if ($reason === '') {
            throw new DomainException('A reason is required to reopen fulfillment.');
        }

        return $reason;
    }

    private function syncOrderItem(int $orderItemId): void
    {
        /** @var OrderItem|null $orderItem */
        $orderItem = OrderItem::query()->lockForUpdate()->find($orderItemId);
        $orderItem?->syncFulfillmentStatus();
    }
}
