<?php

declare(strict_types=1);

namespace App\Services\TextMessages;

use App\Models\Event;
use App\Models\Student;
use App\Models\User;
use App\Services\EventAttendanceService;
use App\Services\StudentProfileService;
use App\Support\TextMessages\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class EventTextRecipients
{
    public function __construct(private EventAttendanceService $attendance) {}

    /** @return Collection<int, Event> */
    public function forDate(User $author, string $date, string $time): Collection
    {
        Gate::forUser($author)->authorize('Send:TextMessage');
        Validator::make(['date' => $date, 'time' => $time], [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ])->validate();
        $timezone = (string) config('app.display_timezone');
        $cutoff = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", $timezone);

        if ($cutoff->format('Y-m-d H:i') !== "{$date} {$time}") {
            throw ValidationException::withMessages(['time' => 'This time does not exist on the selected date.']);
        }

        $query = Event::query();
        Event::applyAdminAccessConstraint($query, $author);

        return $query
            ->with('course')
            ->where('start_time', '>=', $cutoff->setTimezone(config('app.timezone')))
            ->where('start_time', '<', $cutoff->startOfDay()->addDay()->setTimezone(config('app.timezone')))
            ->orderBy('start_time')->orderBy('id')->get()
            ->filter(fn (Event $event): bool => Gate::forUser($author)->allows('update', $event))
            ->values();
    }

    /**
     * @param  list<int|string>  $ids
     * @return Collection<int, Event>
     */
    public function authorizedEvents(User $author, array $ids): Collection
    {
        Gate::forUser($author)->authorize('Send:TextMessage');
        Validator::make(['event_ids' => $ids], [
            'event_ids' => ['required', 'array', 'min:1'],
            'event_ids.*' => ['required', 'integer', 'min:1'],
        ])->validate();
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        $events = Event::query()->with('course')->whereKey($ids)->orderBy('id')->get();

        if ($events->count() !== count($ids)) {
            throw ValidationException::withMessages(['event_ids' => 'An event is no longer available. Review your selection.']);
        }

        foreach ($events as $event) {
            Gate::forUser($author)->authorize('update', $event);
        }

        return $events;
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return array{recipients: array<string, array{phone: string, sources: list<array{student_id: int, contact_id: int, student: string, contact: string}>}>, warnings: list<string>}
     */
    public function resolve(Collection $events, ?array $studentIds = null): array
    {
        $students = collect();
        $courses = [];

        foreach ($events as $event) {
            if ($event->course_id !== null && isset($courses[$event->course_id])) {
                continue;
            }

            if ($event->course_id !== null) {
                $courses[$event->course_id] = true;
            }

            $roster = $this->attendance->eventRosterQuery($event);

            if ($studentIds !== null) {
                $roster->whereIn($event->course_id === null ? 'attendee_id' : 'student_id', $studentIds);
            }

            foreach ($roster->get() as $record) {
                $student = $this->attendance->studentForAttendanceRecord($record);

                if ($student instanceof Student) {
                    $students->put($student->id, $student);
                }
            }
        }

        $profiles = app(StudentProfileService::class);
        $recipients = [];
        $warnings = [];

        foreach ($students->sortKeys() as $student) {
            $contacts = $profiles->medicalWaiver($student)?->emergencyContacts()
                ->where('wants_text_updates', true)->orderBy('id')->get() ?? collect();
            $hasEligibleContact = false;

            foreach ($contacts as $contact) {
                $phone = PhoneNumber::normalize($contact->phone_number);

                if ($phone === null) {
                    $warnings[] = "{$student->fullName}: {$contact->name} has an invalid US phone number.";

                    continue;
                }

                $hasEligibleContact = true;
                $recipients[$phone] ??= ['phone' => $phone, 'sources' => []];
                $recipients[$phone]['sources'][] = [
                    'student_id' => (int) $student->id,
                    'contact_id' => (int) $contact->id,
                    'student' => $student->fullName,
                    'contact' => $contact->name,
                ];
            }

            if (! $hasEligibleContact) {
                $warnings[] = "{$student->fullName} has no eligible emergency contact for text alerts.";
            }
        }

        ksort($recipients);

        return ['recipients' => $recipients, 'warnings' => $warnings];
    }

    /** @param array<int, array<string, mixed>> $events */
    public function stillEligible(array $events, string $phone, array $sources): bool
    {
        $current = $this->resolve(Event::query()->whereKey(array_column($events, 'id'))->get(), array_column($sources, 'student_id'));
        $currentSources = $current['recipients'][$phone]['sources'] ?? [];

        return array_intersect(array_column($sources, 'student_id'), array_column($currentSources, 'student_id')) !== [];
    }
}
