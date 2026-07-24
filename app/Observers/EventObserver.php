<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ReconcileRequiredFormsForCourses;
use App\Models\Event;
use App\Services\HolidayConflictService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Validation\ValidationException;

final readonly class EventObserver
{
    public function __construct(
        private HolidayConflictService $holidayConflicts,
        private Dispatcher $bus,
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

    public function saved(Event $event): void
    {
        $courseIds = collect([
            $event->course_id,
            $event->wasChanged('course_id') ? ($event->getPrevious()['course_id'] ?? null) : null,
        ])->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        if ($courseIds !== []) {
            $this->bus->dispatch(new ReconcileRequiredFormsForCourses($courseIds));
        }
    }

    public function deleted(Event $event): void
    {
        if ($event->course_id !== null) {
            $this->bus->dispatch(new ReconcileRequiredFormsForCourses([(int) $event->course_id]));
        }
    }
}
