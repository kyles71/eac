<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Filament\Admin\Pages\SubstituteRequest;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Models\Course;
use App\Models\Event;
use App\Models\EventSubstituteRequest;
use App\Models\User;

final readonly class EventSubstituteContentService
{
    /** @return array{tokens: array<string, string>, slots: array<string, string>} */
    public function request(EventSubstituteRequest $request): array
    {
        $request->loadMissing(['event.calendar', 'event.course', 'teacher', 'requestedBy']);
        $teacher = $request->teacher;

        return [
            'tokens' => $this->tokens($request, $teacher),
            'slots' => [
                'event-details' => $this->eventDetails($request->event),
                'action' => $this->actionLink(
                    SubstituteRequest::getUrl(['request' => $request], panel: 'admin'),
                    'Review Substitute Request',
                ),
            ],
        ];
    }

    /** @return array{tokens: array<string, string>, slots: array<string, string>} */
    public function reminder(EventSubstituteRequest $request, User $recipient, bool $isTeacher): array
    {
        $request->loadMissing(['event.calendar', 'event.course', 'teacher', 'requestedBy']);
        $url = $isTeacher
            ? SubstituteRequest::getUrl(['request' => $request], panel: 'admin')
            : EventResource::getUrl('view', ['record' => $request->event], panel: 'admin');

        return [
            'tokens' => [
                ...$this->tokens($request, $recipient),
                'recipient.role' => $isTeacher ? 'requested substitute' : 'requesting staff member',
                'request.age_hours' => (string) max(0, $request->created_at?->diffInHours(now()) ?? 0),
            ],
            'slots' => [
                'event-details' => $this->eventDetails($request->event),
                'action' => $this->actionLink($url, $isTeacher ? 'Review Request' : 'View Event'),
            ],
        ];
    }

    /** @return array{tokens: array<string, string>, slots: array<string, string>} */
    public function removed(EventSubstituteRequest $request, string $reason): array
    {
        $request->loadMissing(['event.calendar', 'event.course', 'teacher', 'requestedBy']);

        return [
            'tokens' => [
                ...$this->tokens($request, $request->teacher),
                'removal.reason' => $reason,
            ],
            'slots' => [
                'event-details' => $this->eventDetails($request->event),
            ],
        ];
    }

    /** @return array<string, string> */
    private function tokens(EventSubstituteRequest $request, ?User $recipient): array
    {
        $event = $request->event;
        $displayTimezone = (string) config('app.display_timezone', config('app.timezone'));

        return [
            'app.name' => (string) config('app.name'),
            'recipient.first_name' => $recipient instanceof User ? $recipient->first_name : '',
            'recipient.full_name' => $recipient instanceof User ? $recipient->fullName : '',
            'teacher.full_name' => $request->teacher instanceof User ? $request->teacher->fullName : '',
            'requester.full_name' => $request->requestedBy instanceof User ? $request->requestedBy->fullName : '',
            'event.name' => $event->name,
            'event.starts_at' => $event->start_time?->setTimezone($displayTimezone)->format('F j, Y g:i A T') ?? '',
            'event.ends_at' => $event->end_time?->setTimezone($displayTimezone)->format('F j, Y g:i A T') ?? '',
            'course.name' => $event->course instanceof Course ? $event->course->name : '',
            'request.reason' => (string) $request->request_reason,
        ];
    }

    private function eventDetails(Event $event): string
    {
        return view('mail.event-substitute-details', [
            'event' => $event,
            'displayTimezone' => (string) config('app.display_timezone', config('app.timezone')),
        ])->render();
    }

    private function actionLink(string $url, string $label): string
    {
        return '<a href="'.e($url).'" style="display:inline-block;padding:12px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px">'.e($label).'</a>';
    }
}
