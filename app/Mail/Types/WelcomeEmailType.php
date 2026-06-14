<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\Token;

final class WelcomeEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'user-welcome',
            names: ['en' => 'New User Welcome'],
            description: 'Sent after a user creates an account.',
            category: 'authentication',
            subjects: ['en' => 'Welcome to {{ app.name }}'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>Welcome to {{ app.name }}. Your account is ready.</p>
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'User first name', example: 'Kyle'),
            ],
        );
    }
}
