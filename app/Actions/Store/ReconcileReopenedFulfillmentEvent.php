<?php

declare(strict_types=1);

namespace App\Actions\Store;

use App\Actions\Events\CancelEvent;
use App\Models\Event;
use App\Models\OrderItemFulfillment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final readonly class ReconcileReopenedFulfillmentEvent
{
    public function __construct(
        private RemoveReopenedFulfillmentAttendees $removeAttendees,
        private CancelEvent $cancelEvent,
    ) {}

    /** @param Collection<int, OrderItemFulfillment> $fulfillments */
    public function handle(
        Event $event,
        Collection $fulfillments,
        User $reopenedBy,
        string $reason,
    ): bool {
        $this->removeAttendees->handle($event, $fulfillments);

        if ($event->isCancelled()
            || ! $event->canBeCancelledAt()
            || $event->orderItemFulfillments()->whereNull('voided_at')->exists()
            || $event->attendees()->exists()
            || $event->recurringPrivateLessonCharge()->exists()
            || Gate::forUser($reopenedBy)->denies('cancel', $event)) {
            return false;
        }

        $this->cancelEvent->handle(
            event: $event,
            cancelledBy: $reopenedBy,
            reason: $reason,
            sendEmail: false,
        );

        return true;
    }
}
