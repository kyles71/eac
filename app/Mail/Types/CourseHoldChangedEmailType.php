<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;

final class CourseHoldChangedEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'course-hold-changed',
            names: ['en' => 'Class Hold Changed'],
            description: 'Sent when an administrator changes or releases held class seats.',
            category: 'transactional',
            subjects: ['en' => 'Your class hold has changed'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Your class hold has been updated. Its current status is <strong>{{ course_hold.status }}</strong>.</p>
                {{ slot.course-hold-details }}
                HTML],
            tokens: CourseHoldCreatedEmailType::tokens(),
            slots: CourseHoldCreatedEmailType::slots(),
        );
    }
}
