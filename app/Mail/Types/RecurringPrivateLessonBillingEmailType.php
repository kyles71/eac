<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class RecurringPrivateLessonBillingEmailType implements EmailTypeContract
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
            new Token('billing.month', 'Billing month', example: 'September 2026'),
            new Token('billing.total', 'Total amount requested', example: '$240.00'),
            new Token('billing.lesson_count', 'Number of lessons', example: '4'),
            new Token('lesson.starts_at', 'Lesson start date and time', example: 'September 8, 2026 at 5:00 PM'),
            new Token('lesson.amount', 'Lesson amount', example: '$60.00'),
            new Token('lesson.status', 'Lesson payment status', example: 'Billed'),
            new Token('reminder.days', 'Days before the lesson', example: '7'),
        ];
    }

    /** @return list<SystemSlot> */
    public static function slots(): array
    {
        return [
            new SystemSlot(
                key: 'private-lesson-details',
                label: 'Recurring private lesson details and payment link',
                previewHtml: '<p>The dancer, lesson dates, amounts, statuses, and portal payment link appear here.</p>',
            ),
        ];
    }

    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'recurring-private-lesson-billing',
            names: ['en' => 'Recurring Private Lesson Monthly Billing'],
            description: 'Sent when an owner bills a month of recurring private lessons.',
            category: 'transactional',
            subjects: ['en' => '{{ billing.month }} private lessons for {{ student.full_name }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>{{ billing.lesson_count }} recurring private lessons totaling <strong>{{ billing.total }}</strong> are ready for payment.</p>
                {{ slot.private-lesson-details }}
                HTML],
            tokens: self::tokens(),
            slots: self::slots(),
        );
    }
}
