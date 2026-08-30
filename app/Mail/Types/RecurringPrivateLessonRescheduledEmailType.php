<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\Token;

final class RecurringPrivateLessonRescheduledEmailType implements EmailTypeContract
{
    /** @return list<Token> */
    public static function tokens(): array
    {
        return [
            new Token('app.name', 'Application name', example: 'EAC Plié Portal'),
            new Token('user.first_name', 'Household first name', example: 'Jamie'),
            new Token('user.full_name', 'Household full name', example: 'Jamie Dancer'),
            new Token('student.full_name', 'Dancer full name', example: 'Alex Dancer'),
            new Token('course.name', 'Private lesson name or style', example: 'Ballet Private Lesson'),
            new Token('lesson.previous_starts_at', 'Previous lesson date and time', example: 'September 8, 2026 at 5:00 PM'),
            new Token('lesson.starts_at', 'New lesson date and time', example: 'September 9, 2026 at 6:00 PM'),
            new Token('lesson.amount', 'Lesson amount', example: '$60.00'),
            new Token('change.reason', 'Reason for the change', example: 'Teacher availability changed'),
        ];
    }

    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'recurring-private-lesson-rescheduled',
            names: ['en' => 'Recurring Private Lesson Rescheduled'],
            description: 'Sent to the household and assigned teachers when staff reschedules a recurring private lesson.',
            category: 'transactional',
            subjects: ['en' => 'Rescheduled: {{ student.full_name }} private lesson'],
            bodies: ['en' => <<<'HTML'
                <p>The {{ course.name }} recurring private lesson for {{ student.full_name }} has been rescheduled.</p>
                <p><strong>Previous time:</strong> {{ lesson.previous_starts_at }}<br>
                <strong>New time:</strong> {{ lesson.starts_at }}<br>
                <strong>Reason:</strong> {{ change.reason }}</p>
                HTML],
            tokens: self::tokens(),
        );
    }
}
