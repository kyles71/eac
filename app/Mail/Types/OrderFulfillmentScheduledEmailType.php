<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class OrderFulfillmentScheduledEmailType implements EmailTypeContract
{
    /** @return list<Token> */
    public static function tokens(): array
    {
        return [
            new Token('app.name', 'Application name', example: 'EAC Plié Portal'),
            new Token('event.name', 'Event name', example: 'Avery Private Lesson (MAIN CAMPUS)'),
            new Token('event.date', 'Event date', example: 'September 12, 2026'),
            new Token('event.start_time', 'Event start time', example: '4:00 PM EDT'),
            new Token('event.end_time', 'Event end time', example: '5:00 PM EDT'),
            new Token('event.teachers', 'Event teachers', example: 'Jordan Teacher'),
        ];
    }

    /** @return list<SystemSlot> */
    public static function slots(): array
    {
        return [
            new SystemSlot(
                key: 'order-fulfillment-details',
                label: 'Event details and unit information',
                previewHtml: '<p><strong>Avery Private Lesson (MAIN CAMPUS)</strong><br>September 12, 2026 from 4:00 PM–5:00 PM<br>Teacher: Jordan Teacher</p><p><strong>Unit 1 information</strong><br>Lesson focus: Turns and leaps</p>',
            ),
        ];
    }

    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'order-fulfillment-scheduled',
            names: ['en' => 'Order Fulfillment Scheduled'],
            description: 'Sent to the selected students and event teachers when a private lesson order is scheduled.',
            category: 'transactional',
            subjects: ['en' => 'Scheduled: {{ event.name }} on {{ event.date }}'],
            bodies: ['en' => <<<'HTML'
                <p>A private lesson has been scheduled.</p>
                {{ slot.order-fulfillment-details }}
                HTML],
            tokens: self::tokens(),
            slots: self::slots(),
        );
    }
}
