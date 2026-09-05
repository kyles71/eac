<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\RecurringPrivateLessons\HandleRecurringPrivateLessonEventCancellation;
use App\Actions\RecurringPrivateLessons\SynchronizeRecurringPrivateLessonCharges;
use App\Actions\Store\VoidOrderItemFulfillment;
use App\Enums\RecurringPrivateLessonChargeStatus;
use App\Models\Event;
use App\Models\RecurringPrivateLesson;
use App\Models\RecurringPrivateLessonCharge;
use App\Models\User;
use App\Services\HolidayConflictService;
use Illuminate\Validation\ValidationException;

final readonly class EventObserver
{
    public function __construct(
        private HolidayConflictService $holidayConflicts,
        private SynchronizeRecurringPrivateLessonCharges $synchronizeCharges,
        private HandleRecurringPrivateLessonEventCancellation $handleCancellation,
        private VoidOrderItemFulfillment $voidOrderItemFulfillment,
    ) {}

    public function saving(Event $event): void
    {
        $holiday = $this->holidayConflicts->conflictingHoliday($event);

        if ($holiday !== null) {
            throw ValidationException::withMessages([
                'start_time' => "This event overlaps the \"{$holiday->name}\" holiday.",
            ]);
        }

        if ((! $event->exists || $event->isDirty('start_time'))
            && $event->course_id !== null
            && $event->start_time !== null
            && RecurringPrivateLesson::query()->where('course_id', $event->course_id)->exists()) {
            if (! $event->start_time->isFuture()) {
                throw ValidationException::withMessages([
                    'start_time' => 'Recurring private lessons must be scheduled in the future.',
                ]);
            }

            $charge = $event->exists
                ? RecurringPrivateLessonCharge::query()->where('event_id', $event->id)->first()
                : null;

            if ($charge?->status !== RecurringPrivateLessonChargeStatus::Paid
                && ! $event->start_time->gt(now()->addDay())) {
                throw ValidationException::withMessages([
                    'start_time' => 'Unpaid recurring private lessons must be scheduled more than 24 hours in advance.',
                ]);
            }
        }
    }

    public function created(Event $event): void
    {
        $this->synchronizeRecurringPrivateLesson($event);
    }

    public function updated(Event $event): void
    {
        $this->synchronizeRecurringPrivateLesson($event);

        if ($event->wasChanged('cancelled_at') && $event->cancelled_at !== null) {
            $this->handleCancellation->handle($event);
        }
    }

    public function deleting(Event $event): bool
    {
        $charge = RecurringPrivateLessonCharge::query()
            ->where('event_id', $event->id)
            ->first();

        return ! $charge instanceof RecurringPrivateLessonCharge || (bool) $charge->delete();
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

    private function synchronizeRecurringPrivateLesson(Event $event): void
    {
        if ($event->course_id === null) {
            return;
        }

        $recurringPrivateLesson = RecurringPrivateLesson::query()
            ->where('course_id', $event->course_id)
            ->first();

        if ($recurringPrivateLesson instanceof RecurringPrivateLesson) {
            $this->synchronizeCharges->handle($recurringPrivateLesson);
        }
    }
}
