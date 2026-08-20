<?php

declare(strict_types=1);

namespace App\Actions\RecurringPrivateLessons;

use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Models\Event;
use App\Models\RecurringPrivateLessonCharge;

final class HandleRecurringPrivateLessonEventCancellation
{
    public function handle(Event $event): void
    {
        $charge = RecurringPrivateLessonCharge::query()
            ->where('event_id', $event->id)
            ->first();

        if (! $charge instanceof RecurringPrivateLessonCharge) {
            return;
        }

        if (in_array($charge->status, [
            RecurringPrivateLessonChargeStatus::Scheduled,
            RecurringPrivateLessonChargeStatus::Billed,
        ], true)) {
            $charge->update(['status' => RecurringPrivateLessonChargeStatus::Cancelled]);
        }

        $charge->product?->cartItems()->delete();
        $charge->product()->update(['is_active' => false]);
    }
}
