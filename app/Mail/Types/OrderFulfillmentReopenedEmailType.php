<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\Token;

final class OrderFulfillmentReopenedEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'order-fulfillment-reopened',
            names: ['en' => 'Order Fulfillment Reopened'],
            description: 'Sent to the selected students and event teachers when a scheduled private lesson fulfillment is reopened.',
            category: 'transactional',
            subjects: ['en' => 'Rescheduling needed: {{ event.name }} on {{ event.date }}'],
            bodies: ['en' => <<<'HTML'
                <p>A private lesson fulfillment has been reopened and needs to be rescheduled.</p>
                {{ slot.order-fulfillment-details }}
                <p><strong>Reason / rescheduling update:</strong> {{ fulfillment.reason }}</p>
                HTML],
            tokens: [
                ...OrderFulfillmentScheduledEmailType::tokens(),
                new Token(
                    'fulfillment.reason',
                    'Reason for reopening and the rescheduling plan',
                    example: 'The teacher is unavailable. EAC will contact you with rescheduling options.',
                ),
            ],
            slots: OrderFulfillmentScheduledEmailType::slots(),
        );
    }
}
