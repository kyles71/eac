<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class CourseHoldCreatedEmailType implements EmailTypeContract
{
    /** @return list<Token> */
    public static function tokens(): array
    {
        return [
            new Token('app.name', 'Application name', example: 'EAC Plié Portal'),
            new Token('user.first_name', 'User first name', example: 'Jamie'),
            new Token('user.full_name', 'User full name', example: 'Jamie Dancer'),
            new Token('user.email', 'User email address', example: 'jamie@example.com'),
            new Token('course_hold.number', 'Hold number', example: '42'),
            new Token('course_hold.expires_at', 'Hold expiration date and time', example: 'August 5, 2026 at 5:00 PM'),
            new Token('course_hold.status', 'Current hold status', example: 'Active'),
            new Token('course_hold.seat_count', 'Number of unpurchased held seats', example: '3'),
        ];
    }

    /** @return list<SystemSlot> */
    public static function slots(): array
    {
        return [
            new SystemSlot(
                key: 'course-hold-details',
                label: 'Class hold details and purchase link',
                previewHtml: '<p>The held classes, locked prices, expiration, and purchase link appear here.</p>',
            ),
        ];
    }

    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'course-hold-created',
            names: ['en' => 'Class Hold Created'],
            description: 'Sent when class seats are held for a family.',
            category: 'transactional',
            subjects: ['en' => 'Class seats held for you until {{ course_hold.expires_at }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>We have reserved the following class seats for you until <strong>{{ course_hold.expires_at }}</strong>.</p>
                {{ slot.course-hold-details }}
                HTML],
            tokens: self::tokens(),
            slots: self::slots(),
        );
    }
}
