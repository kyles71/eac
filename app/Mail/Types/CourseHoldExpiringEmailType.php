<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;

final class CourseHoldExpiringEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'course-hold-expiring',
            names: ['en' => 'Class Hold Expiring'],
            description: 'Sent approximately 24 hours before unpurchased held seats expire.',
            category: 'transactional',
            subjects: ['en' => 'Reminder: your held class seats expire {{ course_hold.expires_at }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Your unpurchased held class seats expire <strong>{{ course_hold.expires_at }}</strong>.</p>
                {{ slot.course-hold-details }}
                HTML],
            tokens: CourseHoldCreatedEmailType::tokens(),
            slots: CourseHoldCreatedEmailType::slots(),
        );
    }
}
