<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StudentNoteType;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\StaffNote;
use App\Models\Student;
use App\Models\StudentCommunication;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class StudentNotesService
{
    /** @return Collection<string, covariant array<string, mixed>> */
    public function records(Student $student, User $user): Collection
    {
        Gate::forUser($user)->authorize('view', $student);

        return collect()
            ->concat($this->attendanceNotes($student))
            ->when(
                Gate::forUser($user)->allows('viewAny', StaffNote::class),
                fn (Collection $records): Collection => $records->concat($this->staffNotes($student, $user)),
            )
            ->when(
                Gate::forUser($user)->allows('viewAny', StudentCommunication::class),
                fn (Collection $records): Collection => $records->concat($this->communications($student, $user)),
            )
            ->sortByDesc('sort_at')
            ->keyBy('__key');
    }

    /** @return Collection<int, covariant array<string, mixed>> */
    private function attendanceNotes(Student $student): Collection
    {
        return EventAttendee::query()
            ->with('event')
            ->where('attendee_type', $student->getMorphClass())
            ->where('attendee_id', $student->id)
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->whereHas('event')
            ->get()
            ->map(function (EventAttendee $attendance): array {
                $event = $attendance->event;
                $occurredAt = $event instanceof Event ? $event->start_time : null;

                return $this->record(
                    key: "attendance:{$attendance->id}",
                    source: 'attendance',
                    sourceId: $attendance->id,
                    type: StudentNoteType::Attendance,
                    occurredAt: $occurredAt,
                    dateIncludesTime: true,
                    event: $event,
                    author: null,
                    note: (string) $attendance->notes,
                );
            });
    }

    /** @return Collection<int, covariant array<string, mixed>> */
    private function staffNotes(Student $student, User $user): Collection
    {
        return StaffNote::query()
            ->with(['author', 'media'])
            ->where('student_id', $student->id)
            ->get()
            ->filter(fn (StaffNote $note): bool => Gate::forUser($user)->allows('view', $note))
            ->map(function (StaffNote $note): array {
                $record = $this->record(
                    key: "staff_note:{$note->id}",
                    source: 'staff_note',
                    sourceId: $note->id,
                    type: StudentNoteType::Staff,
                    occurredAt: $note->created_at,
                    dateIncludesTime: true,
                    event: null,
                    author: $note->author,
                    note: (string) $note->note,
                );
                $record['documents'] = $note->getMedia('documents')
                    ->map(fn (Media $media): array => [
                        'file_name' => $media->file_name,
                        'size' => $media->human_readable_size,
                        'added' => $this->formatDate($media->created_at, includesTime: true),
                        'url' => route('admin.staff-notes.documents.download', [
                            'staffNote' => $note->id,
                            'media' => $media->id,
                        ]),
                    ])
                    ->values()
                    ->all();
                $record['details'] = count($record['documents']) === 1
                    ? '1 document'
                    : count($record['documents']).' documents';

                return $record;
            })
            ->values();
    }

    /** @return Collection<int, covariant array<string, mixed>> */
    private function communications(Student $student, User $user): Collection
    {
        return StudentCommunication::query()
            ->with(['author', 'event'])
            ->where('student_id', $student->id)
            ->get()
            ->filter(fn (StudentCommunication $communication): bool => Gate::forUser($user)->allows('view', $communication))
            ->map(function (StudentCommunication $communication): array {
                $record = $this->record(
                    key: "student_communication:{$communication->id}",
                    source: 'student_communication',
                    sourceId: $communication->id,
                    type: StudentNoteType::fromCommunicationType($communication->type),
                    occurredAt: $communication->occurred_at,
                    dateIncludesTime: true,
                    event: $communication->event,
                    author: $communication->author,
                    note: (string) $communication->note,
                );
                $record['stop_light_color'] = $communication->stop_light_color?->value;
                $record['recipient_emails'] = $communication->recipient_emails;
                $record['queued_at'] = $this->formatDate($communication->queued_at, includesTime: true);
                $record['details'] = count($communication->recipient_emails) === 1
                    ? '1 recipient'
                    : count($communication->recipient_emails).' recipients';

                return $record;
            })
            ->values();
    }

    /** @return array<string, mixed> */
    private function record(
        string $key,
        string $source,
        int $sourceId,
        StudentNoteType $type,
        ?CarbonInterface $occurredAt,
        bool $dateIncludesTime,
        ?Event $event,
        ?User $author,
        string $note,
    ): array {
        return [
            '__key' => $key,
            'source' => $source,
            'source_id' => $sourceId,
            'type' => $type->value,
            'date' => $this->formatDate($occurredAt, $dateIncludesTime),
            'sort_at' => $occurredAt?->getTimestamp() ?? 0,
            'event' => $event?->name,
            'event_id' => $event?->id,
            'author' => $author?->full_name,
            'note' => $note,
            'stop_light_color' => null,
            'details' => null,
            'recipient_emails' => [],
            'queued_at' => null,
            'documents' => [],
        ];
    }

    private function formatDate(?CarbonInterface $date, bool $includesTime): ?string
    {
        if (! $date instanceof CarbonInterface) {
            return null;
        }

        return $date
            ->copy()
            ->timezone((string) config('app.display_timezone', config('app.timezone')))
            ->format($includesTime ? 'M j, Y g:i A' : 'M j, Y');
    }
}
