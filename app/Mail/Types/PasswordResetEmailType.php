<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class PasswordResetEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'user-password-reset',
            names: ['en' => 'Password Reset'],
            description: 'Sent when a user requests a password reset.',
            category: 'authentication',
            subjects: ['en' => 'Reset your {{ app.name }} password'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>We received a request to reset your password. Use the secure link below to choose a new one.</p>
                <p>{{ slot.action }}</p>
                <p>If you did not request a password reset, no further action is required.</p>
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'User first name', example: 'Kyle'),
            ],
            slots: [
                new SystemSlot(
                    key: 'action',
                    label: 'Password reset link',
                    previewHtml: '<a href="#">Reset Password</a>',
                ),
            ],
        );
    }
}
