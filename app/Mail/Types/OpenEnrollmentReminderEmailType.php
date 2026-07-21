<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class OpenEnrollmentReminderEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'open-enrollment-reminder',
            names: ['en' => 'Open Enrollment Reminder'],
            description: 'Sent once when course enrollments have remained unassigned for at least one week.',
            category: 'transactional',
            subjects: ['en' => 'Complete your {{ open_enrollments.count }} open {{ open_enrollments.label }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Please complete the next steps for the following {{ open_enrollments.label }} by assigning a student:</p>
                {{ slot.open-enrollments }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC Plié Portal'),
                new Token('user.first_name', 'User first name', example: 'Jamie'),
                new Token('user.full_name', 'User full name', example: 'Jamie Dancer'),
                new Token('user.email', 'User email address', example: 'jamie@example.com'),
                new Token('open_enrollments.count', 'Number of open enrollments in this reminder', example: '2'),
                new Token('open_enrollments.label', 'Correct singular or plural enrollment label', example: 'enrollments'),
            ],
            slots: [
                new SystemSlot(
                    key: 'open-enrollments',
                    label: 'Open enrollments',
                    previewHtml: '<p>The courses with unassigned enrollments appear here.</p>',
                ),
            ],
        );
    }
}
