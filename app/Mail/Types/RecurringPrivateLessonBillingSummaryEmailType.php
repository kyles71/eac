<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class RecurringPrivateLessonBillingSummaryEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'recurring-private-lesson-billing-summary',
            names: ['en' => 'Recurring Private Lesson Billing Summary'],
            description: 'Sent to EAC seven days before month-end with next month\'s recurring private lessons that still need to be billed.',
            category: 'transactional',
            subjects: ['en' => '{{ billing.month }} recurring private lessons awaiting billing'],
            bodies: ['en' => <<<'HTML'
                <p>{{ billing.lesson_count }} recurring private lessons in {{ billing.month }} still need to be billed, totaling {{ billing.total }}.</p>
                {{ slot.private-lesson-billing-summary }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC Plié Portal'),
                new Token('billing.month', 'Upcoming billing month', example: 'September 2026'),
                new Token('billing.lesson_count', 'Number of scheduled lessons', example: '4'),
                new Token('billing.total', 'Total scheduled lesson amount', example: '$240.00'),
            ],
            slots: [
                new SystemSlot(
                    key: 'private-lesson-billing-summary',
                    label: 'Next-month recurring private lessons awaiting billing',
                    previewHtml: '<p>September 8, 2026 at 5:00 PM — Alex Dancer — Ballet Private Lesson — Jamie Teacher — $60.00</p>',
                ),
            ],
        );
    }
}
