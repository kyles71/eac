<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Enums\FulfillmentWorkflow;
use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\OrderItem;
use App\Models\OrderItemFulfillment;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class RecordOrderItemFulfillment
{
    /**
     * @param  list<int>  $unitNumbers
     * @param  list<int>  $studentIds
     * @return Collection<int, OrderItemFulfillment>
     */
    public function handle(
        OrderItem $orderItem,
        array $unitNumbers,
        User $fulfilledBy,
        ?Model $source = null,
        ?string $note = null,
        array $studentIds = [],
    ): Collection {
        $unitNumbers = collect($unitNumbers)
            ->map(fn (mixed $unitNumber): int => (int) $unitNumber)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $studentIds = collect($studentIds)
            ->map(fn (mixed $studentId): int => (int) $studentId)
            ->filter(fn (int $studentId): bool => $studentId > 0)
            ->unique()
            ->values()
            ->all();

        if ($unitNumbers === []) {
            throw new InvalidArgumentException('Select at least one unit to fulfill.');
        }

        return DB::transaction(function () use ($fulfilledBy, $note, $orderItem, $source, $studentIds, $unitNumbers): Collection {
            /** @var OrderItem|null $lockedOrderItem */
            $lockedOrderItem = OrderItem::query()
                ->with('order')
                ->lockForUpdate()
                ->find($orderItem->id);

            if (! $lockedOrderItem instanceof OrderItem) {
                throw new InvalidArgumentException('The order item could not be found.');
            }

            if (! in_array($lockedOrderItem->order->status, [OrderStatus::Completed, OrderStatus::PartiallyRefunded], true)) {
                throw new DomainException('Only completed or partially refunded orders may be fulfilled.');
            }

            $this->validateSource($lockedOrderItem, $source);

            $remainingUnitNumbers = $lockedOrderItem->remainingUnitNumbers();

            if (array_diff($unitNumbers, $remainingUnitNumbers) !== []) {
                throw new DomainException('One or more selected units are already fulfilled or do not exist.');
            }

            $note = filled($note) ? (string) str($note)->squish() : null;
            $fulfillments = new Collection;

            foreach ($unitNumbers as $unitNumber) {
                $fulfillment = new OrderItemFulfillment([
                    'unit_number' => $unitNumber,
                    'fulfilled_by_user_id' => $fulfilledBy->id,
                    'fulfilled_at' => now(),
                    'note' => $note,
                ]);
                $fulfillment->orderItem()->associate($lockedOrderItem);

                if ($source instanceof Model) {
                    $fulfillment->source()->associate($source);
                }

                $fulfillment->save();

                if ($studentIds !== []) {
                    $fulfillment->students()->attach($studentIds);
                }

                $fulfillments->push($fulfillment);
            }

            $lockedOrderItem->syncFulfillmentStatus();

            return $fulfillments;
        });
    }

    private function validateSource(OrderItem $orderItem, ?Model $source): void
    {
        match ($orderItem->fulfillment_workflow) {
            FulfillmentWorkflow::Manual => $this->validateManualSource($source),
            FulfillmentWorkflow::ScheduledEvent => $this->validateScheduledEventSource($source),
            FulfillmentWorkflow::Automatic => throw new DomainException('Automatic order items cannot be fulfilled manually.'),
        };
    }

    private function validateManualSource(?Model $source): void
    {
        if ($source !== null) {
            throw new DomainException('Manual fulfillment cannot have an external source.');
        }
    }

    private function validateScheduledEventSource(?Model $source): void
    {
        if (! $source instanceof Event) {
            throw new DomainException('Scheduled-event fulfillment requires an event.');
        }

        if ($source->course_id !== null || $source->isCancelled()) {
            throw new DomainException('Only active standalone events may fulfill this item.');
        }

        if ($source->start_time === null || ! $source->start_time->isFuture()) {
            throw new DomainException('Only future events may fulfill this item.');
        }
    }
}
