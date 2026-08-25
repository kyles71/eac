<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\Token;

final class RecurringPrivateLessonRemovedEmailType implements EmailTypeContract
{
    /** @return list<Token> */
    public static function tokens(): array
    {
        return [
            ...RecurringPrivateLessonRescheduledEmailType::tokens(),
            new Token(
                'lesson.payment_resolution',
                'Payment resolution for the removed lesson',
                example: 'Store credit: $60.00 in unrestricted store credit was issued.',
            ),
        ];
    }

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
                <p><strong>Payment resolution:</strong> {{ lesson.payment_resolution }}</p>
                HTML],
            tokens: self::tokens(),
        );
    }
}
