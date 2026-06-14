<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class HandcraftedEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'handcrafted',
            names: ['en' => 'Handcrafted Email'],
            description: 'Freeform emails composed by an administrator.',
            category: 'administrative',
            subjects: ['en' => '{{ email.subject }}'],
            bodies: ['en' => '{{ slot.content }}'],
            tokens: [
                new Token('email.subject', 'Email subject', example: 'Class update'),
            ],
            slots: [
                new SystemSlot(
                    key: 'content',
                    label: 'Composed email body',
                    previewHtml: '<p>This content is entered when the email is composed.</p>',
                ),
            ],
            contentEditable: false,
        );
    }
}
