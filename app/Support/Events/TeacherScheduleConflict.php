<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Event;
use App\Models\User;
use App\Support\ApplicationDateTime;
use Carbon\CarbonInterface;

final readonly class TeacherScheduleConflict
{
    public function __construct(
        public User $teacher,
        public Event $proposedEvent,
        public Event $conflictingEvent,
    ) {}

    public function displayMessage(?User $viewer = null, int $redactedConflictCount = 1): string
    {
        if (! $viewer instanceof User || ! $viewer->can('view', $this->conflictingEvent)) {
            if ($redactedConflictCount > 1) {
                return sprintf(
                    '%s: %d events you do not have permission to view conflict with the proposed occurrence (%s).',
                    $this->teacher->fullName,
                    $redactedConflictCount,
                    $this->formattedInterval($this->proposedEvent),
                );
            }

            return sprintf(
                '%s: An event you do not have permission to view conflicts with the proposed occurrence (%s).',
                $this->teacher->fullName,
                $this->formattedInterval($this->proposedEvent),
            );
        }

        return sprintf(
            '%s: "%s" (%s) conflicts with the proposed occurrence (%s).',
            $this->teacher->fullName,
            $this->conflictingEvent->name,
            $this->formattedInterval($this->conflictingEvent),
            $this->formattedInterval($this->proposedEvent),
        );
    }

    public function isVisibleTo(User $viewer): bool
    {
        return $viewer->can('view', $this->conflictingEvent);
    }

    public function redactedDisplayGroupKey(): string
    {
        return implode('|', [
            $this->teacher->getKey(),
            $this->proposedEvent->getKey() ?? 'new',
            $this->proposedEvent->start_time?->toISOString() ?? '',
            $this->proposedEvent->end_time?->toISOString() ?? '',
        ]);
    }

    public function fingerprintPart(): string
    {
        return implode('|', [
            $this->teacher->getKey(),
            $this->proposedEvent->getKey() ?? 'new',
            $this->proposedEvent->start_time?->toISOString() ?? '',
            $this->proposedEvent->end_time?->toISOString() ?? '',
            $this->conflictingEvent->getKey(),
            $this->conflictingEvent->start_time?->toISOString() ?? '',
            $this->conflictingEvent->end_time?->toISOString() ?? '',
            $this->conflictingEvent->updated_at?->toISOString() ?? '',
        ]);
    }

    private function formattedInterval(Event $event): string
    {
        $startsAt = $event->start_time instanceof CarbonInterface
            ? ApplicationDateTime::forDisplay($event->start_time)
            : null;
        $endsAt = $event->end_time instanceof CarbonInterface
            ? ApplicationDateTime::forDisplay($event->end_time)
            : null;

        if (! $startsAt instanceof CarbonInterface) {
            return 'date not set';
        }

        if (! $endsAt instanceof CarbonInterface) {
            return $startsAt->format('M j, Y g:i A T');
        }

        if ($startsAt->isSameDay($endsAt)) {
            return $startsAt->format('M j, Y g:i A').'–'.$endsAt->format('g:i A T');
        }

        return $startsAt->format('M j, Y g:i A T').'–'.$endsAt->format('M j, Y g:i A T');
    }
}
