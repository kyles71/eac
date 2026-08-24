<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;

final class CourseHoldExpiredEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'course-hold-expired',
            names: ['en' => 'Class Hold Expired'],
            description: 'Sent when a class hold expires with unpurchased seats remaining.',
            category: 'transactional',
            subjects: ['en' => 'Your EAC Dance Class Hold Has Expired'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Your EAC dance class hold expired on <strong>{{ course_hold.expires_at }}</strong>.</p>
                <p>Any unpurchased class seats and held prices are no longer reserved.</p>
                HTML],
            tokens: CourseHoldCreatedEmailType::tokens(),
        );
    }
}
