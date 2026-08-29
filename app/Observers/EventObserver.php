<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Store\VoidOrderItemFulfillment;
use App\Models\Event;
use App\Models\User;
use App\Services\HolidayConflictService;
use Illuminate\Validation\ValidationException;

final readonly class EventObserver
{
    public function __construct(
        private HolidayConflictService $holidayConflicts,
        private VoidOrderItemFulfillment $voidOrderItemFulfillment,
    ) {}

    public function saving(Event $event): void
    {
        $holiday = $this->holidayConflicts->conflictingHoliday($event);

        if ($holiday === null) {
            return;
        }

        throw ValidationException::withMessages([
            'start_time' => "This event overlaps the \"{$holiday->name}\" holiday.",
        ]);
    }

    public function deleted(Event $event): void
    {
        $user = auth()->user();

        $this->voidOrderItemFulfillment->forSource(
            source: $event,
            voidedBy: $user instanceof User ? $user : null,
            reason: 'The linked event was deleted.',
        );
    }
}
