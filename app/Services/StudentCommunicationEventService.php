<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class StudentCommunicationEventService
{
    /**
     * @return Builder<Event>
     */
    public function query(Student $student, User $user): Builder
    {
        $query = Event::query()
            ->where(function (Builder $query) use ($student): void {
                $query
                    ->whereHas(
                        'course.enrollments',
                        fn (Builder $query): Builder => $query->where('student_id', $student->id),
                    )
                    ->orWhereHas('attendees', fn (Builder $query): Builder => $query
                        ->where('attendee_type', $student->getMorphClass())
                        ->where('attendee_id', $student->id));
            });

        return Event::applyAdminAccessConstraint($query, $user);
    }

    /**
     * @return array<int, string>
     */
    public function options(Student $student, User $user, ?string $search = null, int $limit = 50): array
    {
        $query = $this->query($student, $user)
            ->orderByDesc('start_time')
            ->orderByDesc('id');

        if (filled($search)) {
            $query->whereLike('name', '%'.mb_trim((string) $search).'%');
        }

        return $query
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Event $event): array => [$event->id => $this->label($event)])
            ->all();
    }

    public function find(Student $student, User $user, int|string|null $eventId): ?Event
    {
        if (blank($eventId)) {
            return null;
        }

        return $this->query($student, $user)->find((int) $eventId);
    }

    public function findOrFail(Student $student, User $user, int|string $eventId): Event
    {
        return $this->find($student, $user, $eventId)
            ?? throw (new ModelNotFoundException)->setModel(Event::class, [(int) $eventId]);
    }

    public function label(Event $event): string
    {
        $date = $event->start_time?->timezone($this->displayTimezone())->format('M j, Y g:i A');

        return $event->name.' — '.($date ?? 'Date not set');
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', config('app.timezone'));
    }
}
