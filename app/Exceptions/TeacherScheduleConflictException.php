<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\User;
use App\Support\Events\TeacherScheduleConflict;
use DomainException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

final class TeacherScheduleConflictException extends DomainException
{
    /**
     * @param  Collection<int, TeacherScheduleConflict>  $conflicts
     */
    public function __construct(public readonly Collection $conflicts)
    {
        if ($conflicts->isEmpty()) {
            throw new InvalidArgumentException('A teacher schedule conflict exception requires at least one conflict.');
        }

        parent::__construct($this->messageFor());
    }

    /** @return list<string> */
    public function displayMessages(?User $viewer = null): array
    {
        if ($viewer instanceof User) {
            return $this->conflicts
                ->groupBy(fn (TeacherScheduleConflict $conflict): string => $conflict->isVisibleTo($viewer)
                    ? 'visible|'.$conflict->fingerprintPart()
                    : 'redacted|'.$conflict->redactedDisplayGroupKey())
                ->map(function (Collection $conflicts) use ($viewer): string {
                    $conflict = $conflicts->first();

                    if (! $conflict instanceof TeacherScheduleConflict) {
                        throw new LogicException('A conflict display group cannot be empty.');
                    }

                    return $conflict->displayMessage($viewer, $conflicts->count());
                })
                ->values()
                ->all();
        }

        return $this->conflicts
            ->map(fn (TeacherScheduleConflict $conflict): string => $conflict->displayMessage($viewer))
            ->values()
            ->all();
    }

    public function messageFor(?User $viewer = null): string
    {
        if ($this->conflicts->count() !== 1) {
            return $this->summaryMessage();
        }

        $conflict = $this->conflicts->first();

        if ($viewer instanceof User && $viewer->can('view', $conflict->conflictingEvent)) {
            return "{$conflict->teacher->fullName} is already assigned to the overlapping event \"{$conflict->conflictingEvent->name}\".";
        }

        if ($viewer instanceof User) {
            return "{$conflict->teacher->fullName} has an overlapping event that you do not have permission to view.";
        }

        return "{$conflict->teacher->fullName} is already assigned to an overlapping event.";
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->conflicts
            ->map(fn (TeacherScheduleConflict $conflict): string => $conflict->fingerprintPart())
            ->sort()
            ->implode("\n"));
    }

    private function summaryMessage(): string
    {
        $teacherCount = $this->conflicts
            ->pluck('teacher.id')
            ->unique()
            ->count();
        $eventCount = $this->conflicts
            ->pluck('conflictingEvent.id')
            ->unique()
            ->count();

        return sprintf(
            '%d %s %s assigned to %d overlapping %s. Review the conflicts below.',
            $teacherCount,
            str('teacher')->plural($teacherCount),
            $teacherCount === 1 ? 'is already' : 'are already',
            $eventCount,
            str('event')->plural($eventCount),
        );
    }
}
