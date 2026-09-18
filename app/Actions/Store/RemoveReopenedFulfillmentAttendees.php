<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Models\Event;
use App\Models\OrderItemFulfillment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class RemoveReopenedFulfillmentAttendees
{
    /** @param Collection<int, OrderItemFulfillment> $fulfillments */
    public function handle(Event $event, Collection $fulfillments): int
    {
        $studentIds = $fulfillments
            ->flatMap(function (OrderItemFulfillment $fulfillment): array {
                $fulfillment->loadMissing([
                    'students',
                    'orderItem.order.user.students',
                ]);

                $students = $fulfillment->students->isNotEmpty()
                    ? $fulfillment->students
                    : $fulfillment->orderItem->order->user->students;

                return array_map('intval', $students->modelKeys());
            })
            ->unique()
            ->reject(fn (int $studentId): bool => $this->hasActiveFulfillment($event, $studentId))
            ->values()
            ->all();

        if ($studentIds === []) {
            return 0;
        }

        return $event->attendees()
            ->where('attendee_type', (new Student)->getMorphClass())
            ->whereIn('attendee_id', $studentIds)
            ->delete();
    }

    private function hasActiveFulfillment(Event $event, int $studentId): bool
    {
        return $event->orderItemFulfillments()
            ->whereNull('voided_at')
            ->where(function (Builder $query) use ($studentId): void {
                $query
                    ->whereHas(
                        'students',
                        fn (Builder $query): Builder => $query->whereKey($studentId),
                    )
                    ->orWhere(function (Builder $query) use ($studentId): void {
                        $query
                            ->whereDoesntHave('students')
                            ->whereHas(
                                'orderItem.order.user.students',
                                fn (Builder $query): Builder => $query->whereKey($studentId),
                            );
                    });
            })
            ->exists();
    }
}
