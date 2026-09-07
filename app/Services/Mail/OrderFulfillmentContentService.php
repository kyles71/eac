<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Event;
use App\Models\OrderItemFulfillment;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class OrderFulfillmentContentService
{
    /**
     * @param  Collection<int, OrderItemFulfillment>  $fulfillments
     * @return array{tokens: array<string, string>, slots: array<string, string>}
     */
    public function for(Event $event, Collection $fulfillments, string $reason = ''): array
    {
        $event->loadMissing('teachers');

        foreach ($fulfillments as $fulfillment) {
            $fulfillment->loadMissing([
                'orderItem.order',
                'orderItem.product',
                'orderItem.questionAnswers',
                'students',
            ]);
        }

        $displayTimezone = (string) config('app.display_timezone', config('app.timezone'));
        $startsAt = $event->start_time?->copy()->timezone($displayTimezone);
        $endsAt = $event->end_time?->copy()->timezone($displayTimezone);
        $teacherNames = $event->teachers
            ->map(fn (User $teacher): string => $teacher->displayName())
            ->join(', ');

        return [
            'tokens' => [
                'app.name' => (string) config('app.name'),
                'event.name' => $event->name,
                'event.date' => $startsAt?->format('F j, Y') ?? '',
                'event.start_time' => $startsAt?->format('g:i A T') ?? '',
                'event.end_time' => $endsAt?->format('g:i A T') ?? '',
                'event.teachers' => $teacherNames,
                'fulfillment.reason' => $reason,
            ],
            'slots' => [
                'order-fulfillment-details' => view('mail.order-fulfillment-details', [
                    'event' => $event,
                    'fulfillments' => $fulfillments
                        ->sortBy(fn (OrderItemFulfillment $fulfillment): string => sprintf(
                            '%020d-%05d',
                            $fulfillment->order_item_id,
                            $fulfillment->unit_number,
                        ))
                        ->values(),
                    'displayTimezone' => $displayTimezone,
                    'teacherNames' => $teacherNames,
                ])->render(),
            ],
        ];
    }
}
