<?php

declare(strict_types=1);

namespace App\Mail\Types;

use Kyle\FilamentMailManager\Contracts\EmailTypeContract;
use Kyle\FilamentMailManager\Data\EmailTypeDefinition;
use Kyle\FilamentMailManager\Data\SystemSlot;
use Kyle\FilamentMailManager\Data\Token;

final class AbandonedCartReminderEmailType implements EmailTypeContract
{
    public function definition(): EmailTypeDefinition
    {
        return new EmailTypeDefinition(
            key: 'abandoned-cart-reminder',
            names: ['en' => 'Abandoned Cart Reminder'],
            description: 'Sent once for available cart items that have not been purchased within 24 hours.',
            category: 'transactional',
            subjects: ['en' => 'You left {{ cart_items.count }} item(s) in your cart'],
            bodies: ['en' => <<<'HTML'
                <p>Hello {{ user.first_name }},</p>
                <p>You still have the following available item(s) waiting in your cart:</p>
                {{ slot.cart-items }}
                HTML],
            tokens: [
                new Token('app.name', 'Application name', example: 'EAC'),
                new Token('user.first_name', 'User first name', example: 'Jamie'),
                new Token('user.full_name', 'User full name', example: 'Jamie Dancer'),
                new Token('user.email', 'User email address', example: 'jamie@example.com'),
                new Token('cart_items.count', 'Number of available cart items', example: '2'),
                new Token('cart_items.total', 'Total for the available cart items', example: '$75.00'),
            ],
            slots: [
                new SystemSlot(
                    key: 'cart-items',
                    label: 'Available cart items',
                    previewHtml: '<p>Available product names, quantities, prices, and the cart total appear here.</p>',
                ),
            ],
        );
    }
}
