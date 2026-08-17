<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;

final class RecurringPrivateLessonRemovedEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'recurring-private-lesson-removed',
            names: ['en' => 'Recurring Private Lesson Removed'],
            description: 'Sent to the household and assigned teachers when staff removes a recurring private lesson.',
            category: 'transactional',
            subjects: ['en' => 'Removed: {{ student.full_name }} private lesson on {{ lesson.previous_starts_at }}'],
            bodies: ['en' => <<<'HTML'
                <p>The {{ course.name }} recurring private lesson for {{ student.full_name }} on {{ lesson.previous_starts_at }} has been removed.</p>
                <p><strong>Reason:</strong> {{ change.reason }}</p>
                HTML],
            tokens: RecurringPrivateLessonRescheduledEmailType::tokens(),
        );
    }
}
