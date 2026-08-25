<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\EventSubstituteRequestStatus;
use App\Models\EventSubstituteRequest;
use App\Models\User;
use App\Services\Mail\EventSubstituteContentService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class SendEventSubstituteRequestReminders
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private EventSubstituteContentService $content,
    ) {}

    /** @return array{expired: int, requests_processed: int, emails_queued: int} */
    public function handle(?CarbonInterface $dateTime = null): array
    {
        $dateTime ??= now();
        $expired = $this->expireClosedRequests($dateTime);
        $requestsProcessed = 0;
        $emailsQueued = 0;
        $hours = max(1, (int) config('app.substitute_request_reminder_hours', 48));

        EventSubstituteRequest::query()
            ->with(['event.calendar', 'event.course', 'teacher', 'requestedBy'])
            ->pending()
            ->whereNull('reminder_processed_at')
            ->where('created_at', '<=', $dateTime->copy()->subHours($hours))
            ->whereHas('event', fn (Builder $query): Builder => $query
                ->whereNull('cancelled_at')
                ->where(function (Builder $query) use ($dateTime): void {
                    $query
                        ->where('end_time', '>', $dateTime)
                        ->orWhere(function (Builder $query) use ($dateTime): void {
                            $query
                                ->whereNull('end_time')
                                ->where('start_time', '>', $dateTime);
                        });
                }))
            ->lazyById()
            ->each(function (EventSubstituteRequest $request) use (&$requestsProcessed, &$emailsQueued): void {
                $emailsQueued += $this->process($request);
                $requestsProcessed++;
            });

        return [
            'expired' => $expired,
            'requests_processed' => $requestsProcessed,
            'emails_queued' => $emailsQueued,
        ];
    }

    private function process(EventSubstituteRequest $request): int
    {
        return DB::transaction(function () use ($request): int {
            $lockedRequest = EventSubstituteRequest::query()
                ->with(['event.calendar', 'event.course', 'teacher', 'requestedBy'])
                ->lockForUpdate()
                ->find($request->id);

            if (! $lockedRequest instanceof EventSubstituteRequest
                || ! $lockedRequest->isPending()
                || $lockedRequest->reminder_processed_at !== null
                || ! $lockedRequest->event->canAcceptSubstituteRequestAt()) {
                return 0;
            }

            $queued = 0;

            if ($lockedRequest->teacher instanceof User) {
                $payload = $this->content->reminder($lockedRequest, $lockedRequest->teacher, true);
                $queued += (int) $this->managedEmail->handle(
                    recipients: $lockedRequest->teacher->email,
                    emailTypeKey: 'event-substitute-request-reminder',
                    tokens: $payload['tokens'],
                    slots: $payload['slots'],
                );
            }

            if ($lockedRequest->requestedBy instanceof User
                && $lockedRequest->requestedBy->email !== $lockedRequest->teacher?->email) {
                $payload = $this->content->reminder($lockedRequest, $lockedRequest->requestedBy, false);
                $queued += (int) $this->managedEmail->handle(
                    recipients: $lockedRequest->requestedBy->email,
                    emailTypeKey: 'event-substitute-request-reminder',
                    tokens: $payload['tokens'],
                    slots: $payload['slots'],
                );
            }

            $lockedRequest->update(['reminder_processed_at' => now()]);

            return $queued;
        });
    }

    private function expireClosedRequests(CarbonInterface $dateTime): int
    {
        $cancelled = EventSubstituteRequest::query()
            ->pending()
            ->whereHas('event', fn (Builder $query): Builder => $query->whereNotNull('cancelled_at'))
            ->update([
                'status' => EventSubstituteRequestStatus::Withdrawn,
                'closed_at' => now(),
                'closure_reason' => 'The event was cancelled.',
            ]);

        $ended = EventSubstituteRequest::query()
            ->pending()
            ->whereHas('event', fn (Builder $query): Builder => $query
                ->where(function (Builder $query) use ($dateTime): void {
                    $query
                        ->where('end_time', '<=', $dateTime)
                        ->orWhere(function (Builder $query) use ($dateTime): void {
                            $query
                                ->whereNull('end_time')
                                ->whereNotNull('start_time')
                                ->where('start_time', '<=', $dateTime);
                        });
                }))
            ->update([
                'status' => EventSubstituteRequestStatus::Expired,
                'closed_at' => now(),
                'closure_reason' => 'The event has ended.',
            ]);

        return $cancelled + $ended;
    }
}
