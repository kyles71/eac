<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Models\Event;
use App\Models\OrderItemFulfillment;
use App\Services\Mail\OrderFulfillmentContentService;
use App\Services\Mail\OrderFulfillmentRecipientsService;
use Illuminate\Support\Collection;

final readonly class SendOrderFulfillmentEmail
{
    public function __construct(
        private QueueManagedEmail $managedEmail,
        private OrderFulfillmentRecipientsService $recipients,
        private OrderFulfillmentContentService $content,
    ) {}

    /** @param Collection<int, OrderItemFulfillment> $fulfillments */
    public function scheduled(Event $event, Collection $fulfillments): int
    {
        return $this->queue(
            $event,
            $fulfillments,
            'order-fulfillment-scheduled',
            $this->content->for($event, $fulfillments),
        );
    }

    /** @param Collection<int, OrderItemFulfillment> $fulfillments */
    public function reopened(Event $event, Collection $fulfillments, string $reason): int
    {
        return $this->queue(
            $event,
            $fulfillments,
            'order-fulfillment-reopened',
            $this->content->for($event, $fulfillments, $reason),
        );
    }

    /**
     * @param  Collection<int, OrderItemFulfillment>  $fulfillments
     * @param  array{tokens: array<string, string>, slots: array<string, string>}  $payload
     */
    private function queue(
        Event $event,
        Collection $fulfillments,
        string $emailTypeKey,
        array $payload,
    ): int {
        $queued = 0;

        foreach ($this->recipients->for($event, $fulfillments) as $recipient) {
            if ($this->managedEmail->handle(
                recipients: $recipient,
                emailTypeKey: $emailTypeKey,
                tokens: $payload['tokens'],
                slots: $payload['slots'],
            )) {
                $queued++;
            }
        }

        return $queued;
    }
}
