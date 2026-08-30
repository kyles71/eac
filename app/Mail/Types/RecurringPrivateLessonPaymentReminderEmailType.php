<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;

final class RecurringPrivateLessonPaymentReminderEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'recurring-private-lesson-payment-reminder',
            names: ['en' => 'Recurring Private Lesson Payment Reminder'],
            description: 'Sent seven and two calendar days before an unpaid recurring private lesson.',
            category: 'transactional',
            subjects: ['en' => 'Payment reminder: {{ student.full_name }} private lesson in {{ reminder.days }} days'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>The following recurring private lesson is still unpaid and will be cancelled if payment is not completed more than 24 hours before it begins.</p>
                {{ slot.private-lesson-details }}
                HTML],
            tokens: RecurringPrivateLessonBillingEmailType::tokens(),
            slots: RecurringPrivateLessonBillingEmailType::slots(),
        );
    }
}
