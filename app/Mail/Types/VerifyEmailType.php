<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class VerifyEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'user-verify-email',
            names: ['en' => 'Verify Email Address'],
            description: 'Sent when a user needs to verify their email address.',
            category: 'authentication',
            subjects: ['en' => 'Verify your {{ app.name }} email address'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Please verify your email address using the secure link below.</p>
                <p>{{ slot.action }}</p>
                <p>If you did not create an account, no further action is required.</p>
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'User first name', example: 'Kyle'),
            ],
            slots: [
                new SystemSlot(
                    key: 'action',
                    label: 'Email verification link',
                    previewHtml: '<a href="#">Verify Email Address</a>',
                ),
            ],
        );
    }
}
