<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;

final class RecurringPrivateLessonAutomaticCancellationEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'recurring-private-lesson-automatic-cancellation',
            names: ['en' => 'Recurring Private Lesson Automatic Cancellation'],
            description: 'Sent when an unpaid recurring private lesson is cancelled at the 24-hour cutoff.',
            category: 'transactional',
            subjects: ['en' => 'Cancelled: {{ student.full_name }} private lesson on {{ lesson.starts_at }}'],
            bodies: ['en' => <<<'HTML'
                <p>The following recurring private lesson was automatically cancelled because payment was not completed more than 24 hours before it began.</p>
                {{ slot.private-lesson-details }}
                HTML],
            tokens: RecurringPrivateLessonBillingEmailType::tokens(),
            slots: RecurringPrivateLessonBillingEmailType::slots(),
        );
    }
}
